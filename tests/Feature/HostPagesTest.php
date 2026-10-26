<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DokployService;
use App\Services\Host\IncusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithHost;
use Tests\TestCase;

class HostPagesTest extends TestCase
{
    use InteractsWithHost, RefreshDatabase;

    public function test_the_incus_page_lists_live_instances_and_cached_images(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->once()->andReturn([
                ...$this->incusOverview(),
                'instances' => [
                    [
                        'name' => 'hermes',
                        'type' => 'container',
                        'status' => 'running',
                        'image' => 'Ubuntu 24.04 LTS',
                    ],
                ],
                'counts' => ['all' => 1, 'running' => 1, 'stopped' => 0],
            ]);
            $mock->shouldReceive('images')->once()->andReturn([
                $this->incusImage(),
                $this->incusImage('virtual-machine'),
            ]);
        });

        $this->actingAs(User::factory()->viewer()->create())
            ->get('/incus')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Incus/Index')
                ->where('incus.counts.all', 1)
                ->where('incus.instances.0.name', 'hermes')
                ->has('images', 2)
                ->where('images.0.type', 'container')
                ->etc()
            );
    }

    public function test_the_dokploy_page_lists_real_projects(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(DokployService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->once()->andReturn([
                'configured' => true,
                'available' => true,
                'url' => 'http://127.0.0.1:3000',
                'error' => null,
                'projects' => [
                    [
                        'id' => 'project-1',
                        'name' => 'validag',
                        'description' => 'Valbarometer',
                        'created_at' => now()->toIso8601String(),
                        'environments' => [],
                    ],
                ],
                'counts' => [
                    'projects' => 1,
                    'environments' => 1,
                    'resources' => 0,
                    'applications' => 0,
                    'compose' => 0,
                    'databases' => 0,
                    'running' => 0,
                    'attention' => 0,
                ],
                'generated_at' => now()->toIso8601String(),
            ]);
        });

        $this->actingAs(User::factory()->viewer()->create())
            ->get('/dokploy')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dokploy/Index')
                ->where('dokploy.configured', true)
                ->where('dokploy.available', true)
                ->where('dokploy.counts.projects', 1)
                ->where('dokploy.projects.0.name', 'validag')
                ->etc()
            );
    }

    public function test_only_administrators_can_open_the_root_terminal(): void
    {
        $this->fakeHostTelemetry();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/terminal')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Terminal/Index')
                ->where('host.hostname', 'pve')
                ->has('sessions')
                ->etc()
            );

        $this->actingAs(User::factory()->operator()->create())
            ->get('/terminal')
            ->assertForbidden();

        $this->actingAs(User::factory()->viewer()->create())
            ->get('/terminal')
            ->assertForbidden();
    }

    public function test_the_audit_trail_is_restricted_to_administrators(): void
    {
        $this->fakeHostTelemetry();

        $this->actingAs(User::factory()->admin()->create())
            ->get('/audit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Audit/Index')
                ->has('logs')
                ->has('filters')
                ->etc()
            );

        $this->actingAs(User::factory()->viewer()->create())
            ->get('/audit')
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 403)
                ->etc()
            );
    }

    public function test_viewers_cannot_run_privileged_host_actions(): void
    {
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer)->post('/docker/action', [
            'action' => 'stop',
            'container' => 'nginx',
        ])->assertForbidden();

        $this->actingAs($viewer)->post('/incus/action', [
            'action' => 'stop',
            'name' => 'hermes',
        ])->assertForbidden();

        $this->actingAs($viewer)->post('/storage/zfs', [
            'action' => 'scrub',
            'pool' => 'default',
        ])->assertForbidden();

        $this->actingAs($viewer)->post('/services/action', [
            'action' => 'restart',
            'unit' => 'nginx.service',
        ])->assertForbidden();

        $this->actingAs($viewer)->post('/system/processes/1/signal', [
            'signal' => 'TERM',
        ])->assertForbidden();

        $this->actingAs($viewer)->post('/dokploy/projects', [
            'name' => 'forbidden',
        ])->assertForbidden();

        $this->actingAs($viewer)->post('/network/tools/ping', [
            'target' => '127.0.0.1',
        ])->assertForbidden();
    }
}
