<?php

namespace Tests\Feature;

use App\Exceptions\HostCommandException;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Host\IncusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithHost;
use Tests\TestCase;

class IncusTest extends TestCase
{
    use InteractsWithHost, RefreshDatabase;

    public function test_operators_can_create_a_container_from_a_cached_image(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('images')->once()->andReturn([$this->incusImage()]);
            $mock->shouldReceive('create')
                ->once()
                ->with('web1', 'container', str_repeat('a', 64), true)
                ->andReturn(['name' => 'web1', 'type' => 'container', 'started' => true]);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/incus/create', [
                'name' => 'web1',
                'type' => 'container',
                'image' => str_repeat('a', 64),
                'start' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'incus.create',
        ]);

        $this->assertStringContainsString(
            'created container web1 from Ubuntu 24.04 · default',
            AuditLog::query()->where('action', 'incus.create')->latest('id')->value('description'),
        );
    }

    public function test_operators_can_create_a_virtual_machine_without_starting_it(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('images')->once()->andReturn([$this->incusImage('virtual-machine')]);
            $mock->shouldReceive('create')
                ->once()
                ->with('db-vm', 'virtual-machine', str_repeat('a', 64), false)
                ->andReturn(['name' => 'db-vm', 'type' => 'virtual-machine', 'started' => false]);
        });

        $this->actingAs(User::factory()->admin()->create())
            ->post('/incus/create', [
                'name' => 'db-vm',
                'type' => 'virtual-machine',
                'image' => str_repeat('a', 64),
                'start' => false,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_the_remote_image_catalog_is_available(): void
    {
        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('remoteImages')->once()->andReturn([
                $this->incusRemoteImage(),
            ]);
        });

        $this->actingAs(User::factory()->viewer()->create())
            ->getJson('/incus/remote-images')
            ->assertOk()
            ->assertJsonPath('images.0.ref', 'images:ubuntu/24.04')
            ->assertJsonPath('images.0.label', 'Ubuntu 24.04 · default');
    }

    public function test_operators_can_create_from_a_remote_image(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('remoteImages')->once()->andReturn([
                $this->incusRemoteImage(),
            ]);
            $mock->shouldReceive('create')
                ->once()
                ->with('web1', 'container', 'images:ubuntu/24.04', true)
                ->andReturn(['name' => 'web1', 'type' => 'container', 'started' => true]);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/incus/create', [
                'name' => 'web1',
                'type' => 'container',
                'image' => 'images:ubuntu/24.04',
                'start' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $audit = AuditLog::query()->where('action', 'incus.create')->latest('id')->first();

        $this->assertNotNull($audit);
        $this->assertStringContainsString('from Ubuntu 24.04 · default', $audit->description);
        $this->assertSame('remote', $audit->meta['image_source']);
        $this->assertSame('images:ubuntu/24.04', $audit->meta['image']);
    }

    public function test_remote_images_must_support_the_selected_instance_type(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('remoteImages')->once()->andReturn([
                $this->incusRemoteImage('container'),
            ]);
            $mock->shouldNotReceive('create');
        });

        $this->actingAs(User::factory()->admin()->create())
            ->post('/incus/create', [
                'name' => 'db-vm',
                'type' => 'virtual-machine',
                'image' => 'images:ubuntu/24.04',
                'start' => true,
            ])
            ->assertSessionHasErrors('image');
    }

    public function test_unknown_remote_images_are_rejected(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('remoteImages')->once()->andReturn([]);
            $mock->shouldNotReceive('create');
        });

        $this->actingAs(User::factory()->admin()->create())
            ->post('/incus/create', [
                'name' => 'web1',
                'type' => 'container',
                'image' => 'images:not-a-real-image',
                'start' => true,
            ])
            ->assertSessionHasErrors('image');
    }

    public function test_creation_rejects_images_that_are_not_cached_on_the_host(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('images')->once()->andReturn([]);
            $mock->shouldNotReceive('create');
        });

        $this->actingAs(User::factory()->admin()->create())
            ->post('/incus/create', [
                'name' => 'web1',
                'type' => 'container',
                'image' => str_repeat('a', 64),
                'start' => true,
            ])
            ->assertSessionHasErrors('image');
    }

    public function test_creation_rejects_an_image_that_does_not_match_the_instance_type(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('images')->once()->andReturn([$this->incusImage('container')]);
            $mock->shouldNotReceive('create');
        });

        $this->actingAs(User::factory()->admin()->create())
            ->post('/incus/create', [
                'name' => 'web1',
                'type' => 'virtual-machine',
                'image' => str_repeat('a', 64),
                'start' => true,
            ])
            ->assertSessionHasErrors('image');
    }

    public function test_instance_names_are_validated(): void
    {
        $this->mock(IncusService::class);

        $this->actingAs(User::factory()->admin()->create())
            ->post('/incus/create', [
                'name' => 'bad name!',
                'type' => 'container',
                'image' => str_repeat('a', 64),
                'start' => true,
            ])
            ->assertSessionHasErrors('name');
    }

    public function test_lifecycle_actions_are_forwarded_and_audited(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('action')
                ->once()
                ->with('force-stop', 'hermes')
                ->andReturn(['name' => 'hermes', 'action' => 'force-stop']);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/incus/action', [
                'action' => 'force-stop',
                'name' => 'hermes',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'incus.force-stop',
        ]);

        $this->assertStringContainsString(
            'ran incus force-stop on hermes',
            AuditLog::query()->where('action', 'incus.force-stop')->latest('id')->value('description'),
        );
    }

    public function test_instances_can_be_deleted_and_the_action_is_audited(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('delete')
                ->once()
                ->with('old-vm')
                ->andReturn(['name' => 'old-vm']);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/incus/delete', ['name' => 'old-vm'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'incus.delete',
        ]);

        $this->assertStringContainsString(
            'deleted Incus instance old-vm',
            AuditLog::query()->where('action', 'incus.delete')->latest('id')->value('description'),
        );
    }

    public function test_failed_helper_operations_return_a_flash_error(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('delete')
                ->once()
                ->andThrow(HostCommandException::unavailable('incus.delete', 'instance is running'));
        });

        $this->actingAs(User::factory()->admin()->create())
            ->post('/incus/delete', ['name' => 'hermes'])
            ->assertRedirect()
            ->assertSessionHas('error', 'Host operation [incus.delete] failed: instance is running');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'incus.delete']);
    }

    public function test_console_logs_are_returned_as_json(): void
    {
        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('logs')
                ->once()
                ->with('hermes')
                ->andReturn(['name' => 'hermes', 'output' => 'Linux boot output']);
        });

        $this->actingAs(User::factory()->viewer()->create())
            ->getJson('/incus/logs?name=hermes')
            ->assertOk()
            ->assertJson(['name' => 'hermes', 'output' => 'Linux boot output']);
    }

    public function test_viewers_cannot_create_or_delete_instances(): void
    {
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer)
            ->post('/incus/create', [
                'name' => 'web1',
                'type' => 'container',
                'image' => str_repeat('a', 64),
                'start' => true,
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post('/incus/delete', ['name' => 'hermes'])
            ->assertForbidden();
    }

    public function test_operators_can_read_instance_configuration(): void
    {
        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('detail')
                ->once()
                ->with('sd-config-test')
                ->andReturn($this->incusDetail());
        });

        $response = $this->actingAs(User::factory()->operator()->create())
            ->getJson('/incus/detail?name=sd-config-test')
            ->assertOk()
            ->assertJsonPath('name', 'sd-config-test')
            ->assertJsonPath('bridges.0.name', 'br0')
            ->assertJsonPath('storage_volumes.0.available', true);

        $this->assertSame('2', $response->json('config')['limits.cpu']);
    }

    public function test_viewers_cannot_read_instance_configuration(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->getJson('/incus/detail?name=sd-config-test')
            ->assertForbidden();
    }

    public function test_operators_can_update_instance_configuration(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('updateConfig')
                ->once()
                ->with(
                    'sd-config-test',
                    [
                        'limits.cpu' => '4',
                        'limits.memory' => '2GiB',
                        'boot.autostart' => 'true',
                        'security.nesting' => 'false',
                    ],
                    'Updated from tests',
                )
                ->andReturn(['name' => 'sd-config-test', 'changed' => ['limits.cpu', 'limits.memory', 'boot.autostart', 'security.nesting', 'description']]);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/incus/config', [
                'name' => 'sd-config-test',
                'description' => 'Updated from tests',
                'config' => [
                    'limits.cpu' => '4',
                    'limits.memory' => '2GiB',
                    'boot.autostart' => 'true',
                    'security.nesting' => 'false',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Configuration saved.')
            ->assertJsonPath('changed.0', 'limits.cpu');

        $audit = AuditLog::query()->where('action', 'incus.config')->latest('id')->first();

        $this->assertNotNull($audit);
        $this->assertSame('sd-config-test', $audit->meta['name']);
        $this->assertContains('limits.cpu', $audit->meta['keys']);
    }

    public function test_unknown_configuration_keys_are_rejected(): void
    {
        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('updateConfig');
        });

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/incus/config', [
                'name' => 'sd-config-test',
                'description' => '',
                'config' => [
                    'limits.cpu' => '2',
                    'security.privileged' => 'true',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('config');
    }

    public function test_operators_can_attach_a_network_device(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('addDevice')
                ->once()
                ->with('sd-config-test', Mockery::on(fn (array $device): bool => $device['device'] === 'eth1'
                    && $device['type'] === 'nic'
                    && $device['parent'] === 'br0'
                    && $device['vlan'] === 100))
                ->andReturn(['name' => 'sd-config-test', 'device' => 'eth1', 'type' => 'nic']);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/incus/config/device', [
                'name' => 'sd-config-test',
                'device' => 'eth1',
                'type' => 'nic',
                'parent' => 'br0',
                'vlan' => 100,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Device eth1 attached.');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'incus.device.add',
        ]);
    }

    public function test_network_devices_require_exactly_one_parent_or_network(): void
    {
        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('addDevice');
        });

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/incus/config/device', [
                'name' => 'sd-config-test',
                'device' => 'eth1',
                'type' => 'nic',
                'parent' => 'br0',
                'network' => 'incusbr0',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent');
    }

    public function test_disk_devices_require_a_valid_source(): void
    {
        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('addDevice');
        });

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/incus/config/device', [
                'name' => 'sd-config-test',
                'device' => 'data',
                'type' => 'disk',
                'source_type' => 'path',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source');
    }

    public function test_operators_can_update_a_device_setting(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('updateDevice')
                ->once()
                ->with('sd-config-test', 'eth1', 'vlan', '200')
                ->andReturn(['name' => 'sd-config-test', 'device' => 'eth1', 'key' => 'vlan']);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/incus/config/device/set', [
                'name' => 'sd-config-test',
                'device' => 'eth1',
                'key' => 'vlan',
                'value' => '200',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Device eth1 updated.');
    }

    public function test_operators_can_remove_and_override_devices(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('removeDevice')
                ->once()
                ->with('sd-config-test', 'data')
                ->andReturn(['name' => 'sd-config-test', 'device' => 'data']);
            $mock->shouldReceive('overrideDevice')
                ->once()
                ->with('sd-config-test', 'root')
                ->andReturn(['name' => 'sd-config-test', 'device' => 'root']);
        });

        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->postJson('/incus/config/device/remove', [
                'name' => 'sd-config-test',
                'device' => 'data',
            ])
            ->assertOk();

        $this->actingAs($operator)
            ->postJson('/incus/config/device/override', [
                'name' => 'sd-config-test',
                'device' => 'root',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'incus.device.remove']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'incus.device.override']);
    }

    public function test_helper_configuration_errors_are_returned_as_json(): void
    {
        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('updateDevice')
                ->once()
                ->andThrow(HostCommandException::unavailable('incus.device_set', 'Root disks can only grow.'));
        });

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/incus/config/device/set', [
                'name' => 'sd-config-test',
                'device' => 'root',
                'key' => 'size',
                'value' => '5GiB',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Host operation [incus.device_set] failed: Root disks can only grow.');
    }

    public function test_operators_can_attach_unix_devices_and_the_mapping_is_audited(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('addDevice')
                ->once()
                ->with('sd-device-test', Mockery::on(fn (array $device): bool => $device['device'] === 'dev0'
                    && $device['type'] === 'unix-char'
                    && $device['source'] === '/dev/kvm'
                    && $device['path'] === '/dev/kvm'))
                ->andReturn(['name' => 'sd-device-test', 'device' => 'dev0', 'type' => 'unix-char']);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/incus/config/device', [
                'name' => 'sd-device-test',
                'device' => 'dev0',
                'type' => 'unix-char',
                'source' => '/dev/kvm',
                'path' => '/dev/kvm',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Device dev0 attached.');

        $audit = AuditLog::query()->where('action', 'incus.device.add')->latest('id')->first();

        $this->assertNotNull($audit);
        $this->assertStringContainsString('added unix-char device dev0 to sd-device-test', $audit->description);
        $this->assertSame('/dev/kvm', $audit->meta['source']);
        $this->assertSame('/dev/kvm', $audit->meta['path']);
    }

    public function test_operators_can_attach_a_physical_gpu_to_a_virtual_machine(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('addDevice')
                ->once()
                ->with('sd-device-vm', Mockery::on(fn (array $device): bool => $device['device'] === 'gpu0'
                    && $device['type'] === 'gpu'
                    && $device['gputype'] === 'physical'
                    && $device['pci'] === '0000:03:00.0'))
                ->andReturn(['name' => 'sd-device-vm', 'device' => 'gpu0', 'type' => 'gpu']);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/incus/config/device', [
                'name' => 'sd-device-vm',
                'device' => 'gpu0',
                'type' => 'gpu',
                'gputype' => 'physical',
                'pci' => '0000:03:00.0',
            ])
            ->assertOk();

        $audit = AuditLog::query()->where('action', 'incus.device.add')->latest('id')->first();

        $this->assertNotNull($audit);
        $this->assertSame('0000:03:00.0', $audit->meta['pci']);
    }

    public function test_operators_can_attach_usb_devices_by_vendor_and_bus_identifier(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('addDevice')
                ->once()
                ->with('sd-device-test', Mockery::on(fn (array $device): bool => $device['device'] === 'usb0'
                    && $device['type'] === 'usb'
                    && $device['vendor_id'] === '195d'
                    && $device['product_id'] === '2030'
                    && $device['serial'] === 'ABC123'
                    && $device['bus'] === 3
                    && $device['devnum'] === 4))
                ->andReturn(['name' => 'sd-device-test', 'device' => 'usb0', 'type' => 'usb']);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/incus/config/device', [
                'name' => 'sd-device-test',
                'device' => 'usb0',
                'type' => 'usb',
                'vendor_id' => '195d',
                'product_id' => '2030',
                'serial' => 'ABC123',
                'bus' => 3,
                'devnum' => 4,
            ])
            ->assertOk();

        $audit = AuditLog::query()->where('action', 'incus.device.add')->latest('id')->first();

        $this->assertNotNull($audit);
        $this->assertSame('195d', $audit->meta['vendor_id']);
        $this->assertSame(4, $audit->meta['devnum']);
    }

    public function test_operators_can_attach_pci_devices_to_a_virtual_machine(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('addDevice')
                ->once()
                ->with('sd-device-vm', Mockery::on(fn (array $device): bool => $device['device'] === 'pci0'
                    && $device['type'] === 'pci'
                    && $device['address'] === '0000:00:1b.0'))
                ->andReturn(['name' => 'sd-device-vm', 'device' => 'pci0', 'type' => 'pci']);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/incus/config/device', [
                'name' => 'sd-device-vm',
                'device' => 'pci0',
                'type' => 'pci',
                'address' => '0000:00:1b.0',
            ])
            ->assertOk();

        $audit = AuditLog::query()->where('action', 'incus.device.add')->latest('id')->first();

        $this->assertNotNull($audit);
        $this->assertSame('0000:00:1b.0', $audit->meta['address']);
    }

    public function test_pass_through_devices_require_a_host_selector(): void
    {
        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('addDevice');
        });

        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->postJson('/incus/config/device', [
                'name' => 'sd-device-test',
                'device' => 'dev0',
                'type' => 'unix-char',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source');

        $this->actingAs($operator)
            ->postJson('/incus/config/device', [
                'name' => 'sd-device-vm',
                'device' => 'gpu0',
                'type' => 'gpu',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pci');

        $this->actingAs($operator)
            ->postJson('/incus/config/device', [
                'name' => 'sd-device-vm',
                'device' => 'pci0',
                'type' => 'pci',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address');

        $this->actingAs($operator)
            ->postJson('/incus/config/device', [
                'name' => 'sd-device-test',
                'device' => 'usb0',
                'type' => 'usb',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('vendor_id');
    }

    public function test_viewers_cannot_attach_host_devices(): void
    {
        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('addDevice');
        });

        $this->actingAs(User::factory()->viewer()->create())
            ->postJson('/incus/config/device', [
                'name' => 'sd-device-test',
                'device' => 'dev0',
                'type' => 'unix-char',
                'source' => '/dev/kvm',
            ])
            ->assertForbidden();
    }

    public function test_helper_pass_through_rejections_are_returned_as_json(): void
    {
        $this->fakeHostTelemetry();

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('addDevice')
                ->once()
                ->andThrow(HostCommandException::unavailable('incus.device_add', 'Stop the virtual machine before adding a physical GPU.'));
        });

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/incus/config/device', [
                'name' => 'sd-device-vm',
                'device' => 'gpu0',
                'type' => 'gpu',
                'pci' => '0000:03:00.0',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Host operation [incus.device_add] failed: Stop the virtual machine before adding a physical GPU.');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'incus.device.add']);
    }
}
