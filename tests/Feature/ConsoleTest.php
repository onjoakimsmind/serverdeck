<?php

namespace Tests\Feature;

use App\Exceptions\ConsoleException;
use App\Exceptions\HostCommandException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ConsoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_operators_can_open_an_incus_container_shell(): void
    {
        $operator = User::factory()->operator()->create(['name' => 'Sam Operator']);

        $this->mock(ConsoleService::class, function (MockInterface $mock) use ($operator): void {
            $mock->shouldReceive('open')
                ->once()
                ->with($operator->id, 'incus', 'container', 'shell', 'hermes', 120, 32)
                ->andReturn([
                    'session' => str_repeat('a', 32),
                    'target' => 'incus',
                    'kind' => 'container',
                    'mode' => 'shell',
                    'name' => 'hermes',
                    'title' => 'hermes · Incus container shell',
                    'cols' => 120,
                    'rows' => 32,
                ]);
        });

        $this->actingAs($operator)
            ->postJson('/consoles', [
                'target' => 'incus',
                'kind' => 'container',
                'mode' => 'shell',
                'name' => 'hermes',
                'cols' => 120,
                'rows' => 32,
            ])
            ->assertOk()
            ->assertJson([
                'session' => str_repeat('a', 32),
                'title' => 'hermes · Incus container shell',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'console.open',
            'user_id' => $operator->id,
        ]);

        $this->assertStringContainsString(
            'opened a console to Incus container hermes',
            AuditLog::query()->where('action', 'console.open')->latest('id')->value('description'),
        );
    }

    public function test_administrators_can_open_a_docker_container_console(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(ConsoleService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('open')
                ->once()
                ->withArgs(fn (int $userId, string $target, string $kind, string $mode, string $name) => $target === 'docker'
                    && $kind === 'container'
                    && $mode === 'shell'
                    && $name === 'dokploy-traefik')
                ->andReturn([
                    'session' => str_repeat('b', 32),
                    'target' => 'docker',
                    'kind' => 'container',
                    'mode' => 'shell',
                    'name' => 'dokploy-traefik',
                    'title' => 'dokploy-traefik · Docker container shell',
                    'cols' => 100,
                    'rows' => 30,
                ]);
        });

        $this->actingAs($admin)
            ->postJson('/consoles', [
                'target' => 'docker',
                'kind' => 'container',
                'mode' => 'shell',
                'name' => 'dokploy-traefik',
                'cols' => 100,
                'rows' => 30,
            ])
            ->assertOk()
            ->assertJson(['session' => str_repeat('b', 32)]);
    }

    public function test_administrators_can_open_a_virtual_machine_serial_console(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(ConsoleService::class, function (MockInterface $mock) use ($admin): void {
            $mock->shouldReceive('open')
                ->once()
                ->with($admin->id, 'incus', 'virtual-machine', 'serial', 'db-vm', 120, 32)
                ->andReturn([
                    'session' => str_repeat('f', 32),
                    'target' => 'incus',
                    'kind' => 'virtual-machine',
                    'mode' => 'serial',
                    'name' => 'db-vm',
                    'title' => 'db-vm · Incus serial console',
                    'cols' => 120,
                    'rows' => 32,
                ]);
        });

        $this->actingAs($admin)
            ->postJson('/consoles', [
                'target' => 'incus',
                'kind' => 'virtual-machine',
                'mode' => 'serial',
                'name' => 'db-vm',
                'cols' => 120,
                'rows' => 32,
            ])
            ->assertOk()
            ->assertJson([
                'session' => str_repeat('f', 32),
                'mode' => 'serial',
            ]);

        $this->assertStringContainsString(
            'opened a console to Incus VM serial console db-vm',
            AuditLog::query()->where('action', 'console.open')->latest('id')->value('description'),
        );
    }

    public function test_administrators_can_open_a_host_root_shell(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(ConsoleService::class, function (MockInterface $mock) use ($admin): void {
            $mock->shouldReceive('open')
                ->once()
                ->with($admin->id, 'host', 'host', 'shell', 'pve', 120, 32)
                ->andReturn([
                    'session' => str_repeat('g', 32),
                    'target' => 'host',
                    'kind' => 'host',
                    'mode' => 'shell',
                    'name' => 'pve',
                    'title' => 'pve · Host root shell',
                    'cols' => 120,
                    'rows' => 32,
                ]);
        });

        $this->actingAs($admin)
            ->postJson('/consoles', [
                'target' => 'host',
                'kind' => 'host',
                'mode' => 'shell',
                'name' => 'pve',
                'cols' => 120,
                'rows' => 32,
            ])
            ->assertOk()
            ->assertJson(['session' => str_repeat('g', 32)]);

        $this->assertStringContainsString(
            'opened a console to Host root shell (pve)',
            AuditLog::query()->where('action', 'console.open')->latest('id')->value('description'),
        );
    }

    public function test_operators_cannot_open_the_host_terminal(): void
    {
        $operator = User::factory()->operator()->create();

        $this->mock(ConsoleService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('open');
        });

        $this->actingAs($operator)
            ->postJson('/consoles', [
                'target' => 'host',
                'kind' => 'host',
                'mode' => 'shell',
                'name' => 'pve',
                'cols' => 120,
                'rows' => 32,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'console.open']);
    }

    public function test_console_requests_are_validated(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(ConsoleService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('open');
        });

        $this->actingAs($admin)
            ->postJson('/consoles', [
                'target' => 'docker',
                'kind' => 'virtual-machine',
                'mode' => 'serial',
                'name' => 'hermes',
                'cols' => 120,
                'rows' => 32,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['kind']);

        $this->actingAs($admin)
            ->postJson('/consoles', [
                'target' => 'docker',
                'kind' => 'container',
                'mode' => 'serial',
                'name' => 'hermes',
                'cols' => 120,
                'rows' => 32,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mode']);

        $this->actingAs($admin)
            ->postJson('/consoles', [
                'target' => 'incus',
                'kind' => 'container',
                'mode' => 'serial',
                'name' => 'hermes',
                'cols' => 120,
                'rows' => 32,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mode']);

        $this->actingAs($admin)
            ->postJson('/consoles', [
                'target' => 'incus',
                'kind' => 'container',
                'mode' => 'tmux',
                'name' => 'hermes',
                'cols' => 120,
                'rows' => 32,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mode']);

        $this->actingAs($admin)
            ->postJson('/consoles', [
                'target' => 'incus',
                'kind' => 'container',
                'name' => 'bad name!',
                'cols' => 10,
                'rows' => 500,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mode', 'name', 'cols', 'rows']);

        $this->actingAs($admin)
            ->postJson('/consoles', [
                'target' => 'kubernetes',
                'kind' => 'container',
                'mode' => 'shell',
                'name' => 'hermes',
                'cols' => 120,
                'rows' => 32,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['target']);

        $this->actingAs($admin)
            ->postJson('/consoles', [
                'target' => 'host',
                'kind' => 'container',
                'mode' => 'shell',
                'name' => 'pve',
                'cols' => 120,
                'rows' => 32,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['kind']);

        $this->actingAs($admin)
            ->postJson('/consoles', [
                'target' => 'host',
                'kind' => 'host',
                'mode' => 'serial',
                'name' => 'pve',
                'cols' => 120,
                'rows' => 32,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mode']);
    }

    public function test_console_helper_failures_are_reported(): void
    {
        $admin = User::factory()->admin()->create();

        $this->mock(ConsoleService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('open')
                ->once()
                ->andThrow(HostCommandException::unavailable('console.open', 'The container is not running.'));
        });

        $this->actingAs($admin)
            ->postJson('/consoles', [
                'target' => 'docker',
                'kind' => 'container',
                'mode' => 'shell',
                'name' => 'stopped-container',
                'cols' => 120,
                'rows' => 32,
            ])
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'Host operation [console.open] failed: The container is not running.',
            ]);
    }

    public function test_console_read_write_resize_and_close_are_forwarded(): void
    {
        $operator = User::factory()->operator()->create(['name' => 'Sam Operator']);
        $session = str_repeat('c', 32);

        $this->mock(ConsoleService::class, function (MockInterface $mock) use ($operator, $session): void {
            $mock->shouldReceive('read')
                ->once()
                ->with($operator->id, $session, 42)
                ->andReturn([
                    'data' => base64_encode('hello'),
                    'offset' => 47,
                    'closed' => false,
                    'exit_code' => null,
                    'lost' => false,
                ]);

            $mock->shouldReceive('write')
                ->once()
                ->with($operator->id, $session, base64_encode('ls -la'))
                ->andReturn(['written' => 6]);

            $mock->shouldReceive('resize')
                ->once()
                ->with($operator->id, $session, 100, 30)
                ->andReturn(['cols' => 100, 'rows' => 30]);

            $mock->shouldReceive('close')
                ->once()
                ->with($operator->id, $session)
                ->andReturn([
                    'user_id' => $operator->id,
                    'target' => 'incus',
                    'kind' => 'virtual-machine',
                    'mode' => 'serial',
                    'name' => 'db-vm',
                ]);
        });

        $this->actingAs($operator)
            ->getJson("/consoles/{$session}?offset=42")
            ->assertOk()
            ->assertJson(['offset' => 47, 'closed' => false]);

        $this->actingAs($operator)
            ->postJson("/consoles/{$session}/input", ['data' => base64_encode('ls -la')])
            ->assertOk()
            ->assertJson(['written' => 6]);

        $this->actingAs($operator)
            ->postJson("/consoles/{$session}/resize", ['cols' => 100, 'rows' => 30])
            ->assertOk()
            ->assertJson(['cols' => 100, 'rows' => 30]);

        $this->actingAs($operator)
            ->deleteJson("/consoles/{$session}")
            ->assertOk()
            ->assertJson(['closed' => true]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'console.close',
            'user_id' => $operator->id,
        ]);

        $this->assertStringContainsString(
            'closed a console to Incus VM serial console db-vm',
            AuditLog::query()->where('action', 'console.close')->latest('id')->value('description'),
        );
    }

    public function test_expired_console_sessions_return_gone(): void
    {
        $operator = User::factory()->operator()->create();
        $session = str_repeat('d', 32);

        $this->mock(ConsoleService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('read')
                ->once()
                ->andThrow(new ConsoleException('Console session not found or expired.'));
        });

        $this->actingAs($operator)
            ->getJson("/consoles/{$session}?offset=0")
            ->assertStatus(410)
            ->assertJson(['message' => 'Console session not found or expired.']);
    }

    public function test_viewers_cannot_open_or_control_consoles(): void
    {
        $viewer = User::factory()->viewer()->create();
        $session = str_repeat('e', 32);

        $this->actingAs($viewer)
            ->postJson('/consoles', [
                'target' => 'incus',
                'kind' => 'container',
                'mode' => 'shell',
                'name' => 'hermes',
                'cols' => 120,
                'rows' => 32,
            ])
            ->assertForbidden();

        $this->actingAs($viewer)->getJson("/consoles/{$session}?offset=0")->assertForbidden();
        $this->actingAs($viewer)->postJson("/consoles/{$session}/input", ['data' => 'YQ=='])->assertForbidden();
        $this->actingAs($viewer)->postJson("/consoles/{$session}/resize", ['cols' => 100, 'rows' => 30])->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/consoles/{$session}")->assertForbidden();
    }
}
