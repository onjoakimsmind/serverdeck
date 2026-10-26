<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Host\DockerService;
use App\Services\Host\HostInventoryService;
use App\Services\Host\IncusService;
use App\Services\Host\SystemdService;
use App\Services\Host\ZfsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithHost;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use InteractsWithHost, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeHostTelemetry();

        $this->mock(HostInventoryService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->andReturn([
                'services' => [],
                'counts' => ['detected' => 0, 'total' => 0],
            ]);
        });

        $this->mock(DockerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->andReturn($this->dockerOverview());
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
    }

    public function test_the_dashboard_renders_live_host_telemetry(): void
    {
        $activity = AuditLog::query()->create([
            'action' => 'auth.login',
            'description' => 'Someone signed in',
            'created_at' => now(),
        ]);

        $this->actingAs(User::factory()->viewer()->create())
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('metrics.host.hostname', 'pve')
                ->where('metrics.cpu.usage', 12.5)
                ->where('metrics.memory.percent', 40)
                ->where('stats.cpu', 12.5)
                ->where('stats.memory', 40)
                ->where('stats.disk', 40)
                ->where('stats.processes', 250)
                ->where('stats.containers_running', 3)
                ->where('stats.containers_total', 4)
                ->where('stats.instances_running', 3)
                ->where('stats.instances_total', 3)
                ->where('stats.pools', 1)
                ->where('stats.datasets', 5)
                ->where('stats.failed_services', 0)
                ->where('stats.gpu_available', false)
                ->has('health.checks')
                ->has('stack')
                ->has('docker')
                ->has('incus')
                ->has('zfs')
                ->has('systemd.counts')
                ->has('activity', 1)
                ->where('activity.0.id', $activity->id)
                ->missing('servers')
                ->missing('deployments')
                ->missing('alerts')
                ->etc()
            );
    }

    public function test_navigation_exposes_live_resource_counts(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('navigation.counts.docker_running', 3)
                ->where('navigation.counts.docker_total', 4)
                ->where('navigation.counts.incus_running', 3)
                ->where('navigation.counts.incus_total', 3)
                ->where('navigation.counts.pools', 1)
                ->where('navigation.counts.failed_services', 0)
                ->etc()
            );
    }

    public function test_legacy_fleet_routes_redirect_to_the_real_host_sections(): void
    {
        $user = User::factory()->viewer()->create();

        $this->actingAs($user)->get('/servers')->assertRedirect('/system');
        $this->actingAs($user)->get('/deployments')->assertRedirect('/docker');
    }
}
