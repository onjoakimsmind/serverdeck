<?php

namespace Tests\Feature;

use App\Exceptions\HostCommandException;
use App\Models\AuditLog;
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

class UpdatesTest extends TestCase
{
    use InteractsWithHost, RefreshDatabase;

    public function test_the_updates_page_lists_pending_packages(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(UpdateService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->once()->andReturn($this->updatesOverview());
        });

        $this->actingAs(User::factory()->viewer()->create())
            ->get('/updates')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Updates/Index')
                ->where('updates.counts.total', 2)
                ->where('updates.counts.security', 1)
                ->where('updates.packages.0.name', 'xxd')
                ->where('updates.packages.0.is_new', true)
                ->where('updates.packages.1.name', 'ubuntu-server-minimal')
                ->where('updates.reboot_required', false)
                ->where('updates.unattended_upgrades_active', true));
    }

    public function test_viewers_cannot_refresh_or_install_updates(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(UpdateService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('refresh');
            $mock->shouldNotReceive('upgrade');
        });

        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer)->post('/updates/refresh')->assertForbidden();
        $this->actingAs($viewer)->post('/updates/upgrade')->assertForbidden();
    }

    public function test_the_updates_page_reports_the_detected_package_manager(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(UpdateService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->once()->andReturn(array_merge($this->updatesOverview(), [
                'manager' => 'pacman',
                'manager_label' => 'Pacman',
                'security_supported' => false,
                'security_note' => 'Pacman does not expose security advisory metadata.',
            ]));
        });

        $this->actingAs(User::factory()->viewer()->create())
            ->get('/updates')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Updates/Index')
                ->where('updates.manager', 'pacman')
                ->where('updates.manager_label', 'Pacman')
                ->where('updates.security_supported', false)
            );
    }

    public function test_operators_can_refresh_package_lists(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(UpdateService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refresh')->once()->andReturn($this->updatesOverview());
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/updates/refresh')
            ->assertRedirect()
            ->assertSessionHas('success', 'Package lists refreshed. 2 updates available.');

        $this->assertDatabaseHas('audit_logs', ['action' => 'apt.refresh']);
    }

    public function test_refresh_audit_action_uses_the_detected_package_manager(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(UpdateService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refresh')->once()->andReturn(array_merge($this->updatesOverview(), [
                'manager' => 'dnf',
                'manager_label' => 'DNF',
            ]));
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/updates/refresh')
            ->assertRedirect()
            ->assertSessionHas('success', 'Package lists refreshed. 2 updates available.');

        $this->assertDatabaseHas('audit_logs', ['action' => 'dnf.refresh']);
    }

    public function test_operators_can_install_pending_updates(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(UpdateService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->once()->andReturn($this->updatesOverview());
            $mock->shouldReceive('upgrade')->once()->andReturn([
                'upgrade' => [
                    'completed_at' => now()->timestamp,
                    'reboot_required' => false,
                    'reboot_required_before' => false,
                    'reboot_packages' => [],
                    'output_tail' => '0 upgraded, 0 newly installed, 0 to remove.',
                ],
                'status' => $this->updatesOverview(),
            ]);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/updates/upgrade')
            ->assertRedirect()
            ->assertSessionHas('success', 'Installed 2 package updates.');

        $log = AuditLog::query()->where('action', 'apt.upgrade')->first();

        $this->assertNotNull($log);
        $this->assertSame(['xxd', 'ubuntu-server-minimal'], $log->meta['packages']);
        $this->assertSame(1, $log->meta['security']);
        $this->assertFalse($log->meta['reboot_required']);
    }

    public function test_upgrade_success_reports_a_required_reboot(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(UpdateService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->once()->andReturn($this->updatesOverview());
            $mock->shouldReceive('upgrade')->once()->andReturn([
                'upgrade' => [
                    'completed_at' => now()->timestamp,
                    'reboot_required' => true,
                    'reboot_required_before' => false,
                    'reboot_packages' => ['linux-image-7.0.0-39-generic'],
                    'output_tail' => 'Processing triggers for linux-image-7.0.0-39-generic.',
                ],
                'status' => array_merge($this->updatesOverview(), [
                    'reboot_required' => true,
                    'reboot_packages' => ['linux-image-7.0.0-39-generic'],
                ]),
            ]);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/updates/upgrade')
            ->assertRedirect()
            ->assertSessionHas('success', 'Installed 2 package updates. A reboot is required to finish applying kernel or library updates.');

        $log = AuditLog::query()->where('action', 'apt.upgrade')->first();

        $this->assertNotNull($log);
        $this->assertTrue($log->meta['reboot_required']);
    }

    public function test_refresh_failures_are_returned_as_flash_errors(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(UpdateService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refresh')
                ->once()
                ->andThrow(HostCommandException::unavailable('apt.refresh', 'apt-get update failed.'));
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/updates/refresh')
            ->assertRedirect()
            ->assertSessionHas('error', 'Host operation [apt.refresh] failed: apt-get update failed.');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'apt.refresh']);
    }

    public function test_upgrade_failures_are_returned_as_flash_errors(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(UpdateService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->once()->andReturn($this->updatesOverview());
            $mock->shouldReceive('upgrade')
                ->once()
                ->andThrow(HostCommandException::unavailable('apt.upgrade', 'E: Unable to lock the administration directory.'));
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/updates/upgrade')
            ->assertRedirect()
            ->assertSessionHas('error', 'Host operation [apt.upgrade] failed: E: Unable to lock the administration directory.');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'apt.upgrade']);
    }

    public function test_updates_health_check_reports_reboots_and_security_updates(): void
    {
        $this->fakeMetrics();
        Cache::forget('host.health.report');

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
            $mock->shouldReceive('overview')->andReturn($this->smartOverview());
        });

        $this->mock(UpdateService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->once()->andReturn(array_merge($this->updatesOverview(), [
                'reboot_required' => true,
                'reboot_packages' => ['linux-image-7.0.0-39-generic'],
            ]));
        });

        $report = $this->app->make(HostHealthService::class)->report();
        $check = collect($report['checks'])->firstWhere('key', 'updates');

        $this->assertNotNull($check);
        $this->assertSame('warning', $check['status']);
        $this->assertStringContainsString('2 updates', $check['detail']);
        $this->assertStringContainsString('reboot required', $check['detail']);
        $this->assertTrue($report['summary']['resources']['updates']['reboot_required']);
        $this->assertSame(2, $report['summary']['resources']['updates']['total']);
        $this->assertSame(1, $report['summary']['resources']['updates']['security']);
    }

    public function test_the_update_service_caches_status_and_normalises_counts(): void
    {
        Cache::forget('host.updates.status');

        $this->mock(PrivilegedHost::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->with('packages.status', [], 180)
                ->andReturn(array_diff_key($this->updatesOverview(), ['available' => true, 'error' => true]));
        });

        $service = $this->app->make(UpdateService::class);
        $first = $service->overview();
        $second = $service->overview();

        $this->assertTrue($first['available']);
        $this->assertNull($first['error']);
        $this->assertSame(2, $first['counts']['total']);
        $this->assertSame($first, $second);
    }

    public function test_the_update_service_reports_an_unavailable_helper(): void
    {
        Cache::forget('host.updates.status');

        $this->mock(PrivilegedHost::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->with('packages.status', [], 180)
                ->andThrow(HostCommandException::unavailable('packages.status', 'apt-get simulation failed.'));
        });

        $report = $this->app->make(UpdateService::class)->overview();

        $this->assertFalse($report['available']);
        $this->assertStringContainsString('apt-get simulation failed', $report['error']);
        $this->assertSame(0, $report['counts']['total']);
        $this->assertSame([], $report['packages']);
    }
}
