<?php

namespace Tests\Feature;

use App\Exceptions\HostCommandException;
use App\Models\User;
use App\Services\Host\DockerService;
use App\Services\Host\HostHealthService;
use App\Services\Host\IncusService;
use App\Services\Host\PrivilegedHost;
use App\Services\Host\SmartService;
use App\Services\Host\SystemdService;
use App\Services\Host\UpdateService;
use App\Services\Host\ZfsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithHost;
use Tests\TestCase;

class DisksTest extends TestCase
{
    use InteractsWithHost, RefreshDatabase;

    public function test_the_disk_health_page_lists_smart_devices(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(SmartService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->once()->andReturn($this->smartOverview());
        });

        $this->actingAs(User::factory()->viewer()->create())
            ->get('/disks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Disks/Index')
                ->where('smart.counts.total', 2)
                ->where('smart.counts.critical', 1)
                ->where('smart.devices.1.name', 'sdc')
                ->where('smart.devices.1.status', 'critical')
                ->where('smart.devices.1.reasons.0', '294 pending sector(s)'));
    }

    public function test_viewers_can_refresh_smart_data(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(SmartService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->once()->with(true)->andReturn($this->smartOverview());
        });

        $this->actingAs(User::factory()->viewer()->create())
            ->post('/disks/scan')
            ->assertRedirect()
            ->assertSessionHas('success', 'SMART data refreshed for 2 disks.');
    }

    public function test_operators_can_start_and_abort_self_tests(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(SmartService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('startSelfTest')
                ->once()
                ->with('/dev/sda', 'short')
                ->andReturn(['device' => '/dev/sda', 'mode' => 'short', 'started' => true]);
            $mock->shouldReceive('abortSelfTest')
                ->once()
                ->with('/dev/sda')
                ->andReturn(['device' => '/dev/sda', 'aborted' => true]);
            $mock->shouldReceive('forget')->twice();
        });

        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->post('/disks/test', ['device' => '/dev/sda', 'mode' => 'short'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($operator)
            ->post('/disks/test/abort', ['device' => '/dev/sda'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', ['action' => 'smart.selftest']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'smart.selftest.abort']);
    }

    public function test_viewers_cannot_start_self_tests(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(SmartService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('startSelfTest');
        });

        $this->actingAs(User::factory()->viewer()->create())
            ->post('/disks/test', ['device' => '/dev/sda', 'mode' => 'short'])
            ->assertForbidden();
    }

    public function test_self_tests_validate_the_device_and_mode(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(SmartService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('startSelfTest');
        });

        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->post('/disks/test', ['device' => 'sda', 'mode' => 'quick'])
            ->assertSessionHasErrors(['device', 'mode']);
    }

    public function test_smart_health_is_included_in_the_host_health_report(): void
    {
        $this->fakeMetrics();

        $this->mock(DockerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->with(false)->andReturn($this->dockerOverview());
        });

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->andReturn($this->incusOverview());
        });

        $this->mock(ZfsService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->andReturn($this->zfsOverview());
        });

        $this->mock(SystemdService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->andReturn($this->systemdOverview());
        });

        $this->mock(SmartService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->once()->andReturn($this->smartOverview());
        });

        $this->mock(UpdateService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->once()->andReturn($this->updatesOverview());
        });

        $report = $this->app->make(HostHealthService::class)->report();
        $smartCheck = collect($report['checks'])->firstWhere('key', 'smart');

        $this->assertNotNull($smartCheck);
        $this->assertSame('critical', $smartCheck['status']);
        $this->assertSame(1, $report['summary']['resources']['smart']['critical']);
        $this->assertStringContainsString('sdc', $smartCheck['detail']);
    }

    public function test_the_smart_service_caches_scans_and_normalises_attention_counts(): void
    {
        Cache::forget('host.smart.scan');

        $this->mock(PrivilegedHost::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->with('smart.scan', [], 120)
                ->andReturn([
                    'devices' => [
                        ['name' => 'sda', 'status' => 'ok', 'temperature' => 30],
                        ['name' => 'sdb', 'status' => 'warning', 'temperature' => 42],
                        ['name' => 'sdc', 'status' => 'critical', 'temperature' => 50],
                    ],
                    'counts' => ['total' => 3, 'ok' => 1, 'warning' => 1, 'critical' => 1, 'info' => 0],
                    'scanned_at' => 1793000000,
                ]);
        });

        $service = $this->app->make(SmartService::class);
        $first = $service->overview();
        $second = $service->overview();

        $this->assertTrue($first['available']);
        $this->assertIsArray($first['devices']);
        $this->assertSame('sda', $first['devices'][0]['name']);
        $this->assertSame(2, $first['attention']);
        $this->assertSame(50, $first['max_temperature']);
        $this->assertSame($first, $second);
    }

    public function test_the_smart_service_reports_an_unavailable_helper(): void
    {
        Cache::forget('host.smart.scan');

        $this->mock(PrivilegedHost::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->with('smart.scan', [], 120)
                ->andThrow(HostCommandException::unavailable('smart.scan', 'smartmontools is not installed.'));
        });

        $report = $this->app->make(SmartService::class)->overview();

        $this->assertFalse($report['available']);
        $this->assertStringContainsString('smartmontools', $report['error']);
        $this->assertSame(0, $report['counts']['total']);
    }
}
