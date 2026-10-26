<?php

namespace Tests\Feature;

use App\Exceptions\HostCommandException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Host\DockerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithHost;
use Tests\TestCase;

class DockerDevicesTest extends TestCase
{
    use InteractsWithHost, RefreshDatabase;

    public function test_operators_can_replace_container_devices_and_the_change_is_audited(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(DockerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('updateDevices')
                ->once()
                ->with('sd-docker-device-test', [
                    ['host' => '/dev/kvm', 'container' => '/dev/kvm', 'permissions' => 'rwm'],
                    ['host' => '/dev/net/tun', 'container' => '/dev/net/tun', 'permissions' => 'rw'],
                ], true)
                ->andReturn([
                    'container' => 'sd-docker-device-test',
                    'id' => str_repeat('b', 64),
                    'cleanup_error' => null,
                ]);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/docker/devices', [
                'container' => 'sd-docker-device-test',
                'devices' => [
                    ['host' => '/dev/kvm', 'container' => '/dev/kvm', 'permissions' => 'rwm'],
                    ['host' => '/dev/net/tun', 'container' => '/dev/net/tun', 'permissions' => 'rw'],
                ],
                'gpus' => true,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Devices updated. The container was recreated with the same configuration and remains stopped.')
            ->assertJsonPath('container', 'sd-docker-device-test')
            ->assertJsonPath('id', str_repeat('b', 64));

        $audit = AuditLog::query()->where('action', 'docker.devices')->latest('id')->first();

        $this->assertNotNull($audit);
        $this->assertStringContainsString('updated devices for Docker container sd-docker-device-test', $audit->description);
        $this->assertSame('sd-docker-device-test', $audit->meta['container']);
        $this->assertTrue($audit->meta['gpus']);
        $this->assertCount(2, $audit->meta['devices']);
        $this->assertSame(str_repeat('b', 64), $audit->meta['recreated_id']);
    }

    public function test_cleanup_failures_are_reported_to_the_operator(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(DockerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('updateDevices')
                ->once()
                ->andReturn([
                    'container' => 'sd-docker-device-test',
                    'id' => str_repeat('c', 64),
                    'previous' => 'serverdeck-old-abc123',
                    'cleanup_error' => 'container is in use',
                ]);
        });

        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/docker/devices', [
                'container' => 'sd-docker-device-test',
                'devices' => [],
                'gpus' => false,
            ])
            ->assertOk()
            ->assertJsonPath('cleanup_error', 'container is in use')
            ->assertJsonPath('message', 'Devices updated. The container was recreated with the same configuration and remains stopped. The previous container is still present as serverdeck-old-abc123.');
    }

    public function test_viewers_cannot_change_container_devices(): void
    {
        $this->mock(DockerService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('updateDevices');
        });

        $this->actingAs(User::factory()->viewer()->create())
            ->postJson('/docker/devices', [
                'container' => 'sd-docker-device-test',
                'devices' => [
                    ['host' => '/dev/kvm', 'container' => '/dev/kvm', 'permissions' => 'rwm'],
                ],
                'gpus' => false,
            ])
            ->assertForbidden();
    }

    public function test_device_payloads_are_validated_before_recreation(): void
    {
        $this->mock(DockerService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('updateDevices');
        });

        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->postJson('/docker/devices', [
                'container' => 'sd-docker-device-test',
                'devices' => [
                    ['host' => '/etc/passwd', 'container' => 'relative', 'permissions' => 'rwx'],
                ],
                'gpus' => 'yes',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'devices.0.host',
                'devices.0.container',
                'devices.0.permissions',
                'gpus',
            ]);

        $this->actingAs($operator)
            ->postJson('/docker/devices', [
                'container' => 'sd-docker-device-test',
                'devices' => 'none',
                'gpus' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('devices');
    }

    public function test_helper_rejections_are_returned_as_json_without_an_audit_entry(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(DockerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('updateDevices')
                ->once()
                ->andThrow(HostCommandException::unavailable('docker.device_update', 'Stop the container before changing devices.'));
        });

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/docker/devices', [
                'container' => 'sd-docker-device-test',
                'devices' => [],
                'gpus' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Host operation [docker.device_update] failed: Stop the container before changing devices.');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'docker.devices']);
    }
}
