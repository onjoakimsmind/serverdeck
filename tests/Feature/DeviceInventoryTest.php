<?php

namespace Tests\Feature;

use App\Exceptions\HostCommandException;
use App\Models\User;
use App\Services\Host\DeviceInventoryService;
use App\Services\Host\PrivilegedHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

class DeviceInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_host_devices_are_normalised_with_pass_through_capabilities(): void
    {
        $this->mock(PrivilegedHost::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->with('device.inventory', [], 45)
                ->andReturn($this->inventoryPayload());
        });

        $inventory = $this->app->make(DeviceInventoryService::class)->overview();

        $this->assertTrue($inventory['available']);
        $this->assertNull($inventory['error']);
        $this->assertSame(1700000000, $inventory['scanned_at']);
        $this->assertSame(['enabled' => true, 'groups' => 64], $inventory['iommu']);
        $this->assertSame(['pci' => 2, 'usb' => 1, 'unix' => 1, 'block' => 1, 'gpus' => 1], $inventory['counts']);

        $gpu = $inventory['pci'][0];
        $this->assertSame(['gpu'], $gpu['incus']['container']);
        $this->assertSame(['gpu', 'pci'], $gpu['incus']['virtual-machine']);
        $this->assertSame(['device', 'gpu'], $gpu['docker']);
        $this->assertSame([], $gpu['used_by']);
        $this->assertSame([], $gpu['warnings']);

        $nic = $inventory['pci'][1];
        $this->assertSame([], $nic['incus']['container']);
        $this->assertSame(['pci'], $nic['incus']['virtual-machine']);
        $this->assertSame(['device'], $nic['docker']);

        $this->assertSame(['usb'], $inventory['usb'][0]['incus']['container']);
        $this->assertSame(['usb'], $inventory['usb'][0]['incus']['virtual-machine']);

        $this->assertSame(['unix-char'], $inventory['unix'][0]['incus']['container']);
        $this->assertSame([], $inventory['unix'][0]['incus']['virtual-machine']);

        $this->assertSame(['unix-block'], $inventory['block'][0]['incus']['container']);
        $this->assertSame([], $inventory['block'][0]['incus']['virtual-machine']);
        $this->assertSame(['device'], $inventory['block'][0]['docker']);

        $this->assertSame(['gpu'], $inventory['gpus'][0]['incus']['container']);
        $this->assertSame(['gpu', 'pci'], $inventory['gpus'][0]['incus']['virtual-machine']);
        $this->assertSame(['device', 'gpu'], $inventory['gpus'][0]['docker']);
        $this->assertSame(['/dev/dri/card0'], $inventory['gpus'][0]['drm_nodes']);

        $this->assertFalse($inventory['nvidia']['available']);
        $this->assertSame('No devices were found', $inventory['nvidia']['error']);
        $this->assertFalse($inventory['docker']['nvidia_runtime']);
        $this->assertSame('active', $inventory['docker']['swarm']);

        $this->assertContains('NVIDIA telemetry is unavailable: No devices were found. GPU workloads may not function until the host driver is repaired.', $inventory['warnings']);
        $this->assertContains('Docker GPU requests need the NVIDIA container runtime, which is not registered with this Docker engine.', $inventory['warnings']);
        $this->assertContains('Docker Swarm is active. Swarm-managed containers cannot have devices attached; reconfigure the service or use a standalone container.', $inventory['warnings']);
    }

    public function test_inventory_is_cached_and_can_be_refreshed(): void
    {
        $this->mock(PrivilegedHost::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->twice()
                ->with('device.inventory', [], 45)
                ->andReturn($this->inventoryPayload());
        });

        $service = $this->app->make(DeviceInventoryService::class);
        $first = $service->overview();
        $second = $service->overview();

        $this->assertSame($first, $second);

        $service->overview(true);
    }

    public function test_inventory_failures_are_reported_without_throwing(): void
    {
        $this->mock(PrivilegedHost::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andThrow(HostCommandException::unavailable('device.inventory', 'helper unavailable'));
        });

        $inventory = $this->app->make(DeviceInventoryService::class)->overview();

        $this->assertFalse($inventory['available']);
        $this->assertSame('Host operation [device.inventory] failed: helper unavailable', $inventory['error']);
        $this->assertSame(['pci' => 0, 'usb' => 0, 'unix' => 0, 'block' => 0, 'gpus' => 0], $inventory['counts']);
        $this->assertSame([], $inventory['gpus']);
        $this->assertSame([], $inventory['warnings']);
    }

    public function test_all_authenticated_roles_can_read_the_device_api(): void
    {
        $this->mock(DeviceInventoryService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->once()->with(false)->andReturn([
                'available' => true,
                'counts' => ['pci' => 1, 'usb' => 1, 'unix' => 2, 'block' => 1, 'gpus' => 1],
            ]);
        });

        $this->actingAs(User::factory()->viewer()->create())
            ->getJson('/api/devices?refresh=1')
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('counts.gpus', 1);
    }

    public function test_only_operators_can_force_an_inventory_refresh(): void
    {
        $this->mock(DeviceInventoryService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('overview')->once()->with(true)->andReturn([
                'available' => true,
                'counts' => ['pci' => 0, 'usb' => 0, 'unix' => 0, 'block' => 0, 'gpus' => 0],
            ]);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->getJson('/api/devices?refresh=1')
            ->assertOk();
    }

    public function test_guests_cannot_read_the_device_api(): void
    {
        $this->getJson('/api/devices')->assertUnauthorized();
    }

    /**
     * @return array<string, mixed>
     */
    private function inventoryPayload(): array
    {
        return [
            'scanned_at' => 1700000000,
            'iommu' => ['enabled' => true, 'groups' => 64],
            'pci' => [
                [
                    'address' => '0000:03:00.0',
                    'vendor_id' => '10de',
                    'device_id' => '0e08',
                    'vendor' => 'NVIDIA Corporation',
                    'device' => 'GK107 [NVS 510]',
                    'class_id' => '030000',
                    'class_name' => 'VGA compatible controller',
                    'driver' => 'nvidia',
                    'iommu_group' => '57',
                    'boot_vga' => false,
                    'kind' => 'gpu',
                    'ready' => false,
                    'warnings' => [],
                    'used_by' => [],
                ],
                [
                    'address' => '0000:00:1b.0',
                    'vendor_id' => '8086',
                    'device_id' => 'a348',
                    'vendor' => 'Intel Corporation',
                    'device' => '200 Series PCH HD Audio',
                    'class_id' => '040300',
                    'class_name' => 'Audio device',
                    'driver' => 'snd_hda_intel',
                    'iommu_group' => '10',
                    'boot_vga' => false,
                    'kind' => 'other',
                    'ready' => true,
                    'warnings' => [],
                    'used_by' => [],
                ],
            ],
            'usb' => [
                [
                    'bus' => 3,
                    'device' => 4,
                    'vendor_id' => '195d',
                    'product_id' => '2030',
                    'vendor' => 'SONiX',
                    'product' => 'Keyboard',
                    'serial' => null,
                    'ready' => true,
                    'warnings' => [],
                    'used_by' => [],
                ],
            ],
            'unix' => [
                [
                    'path' => '/dev/kvm',
                    'real_path' => '/dev/kvm',
                    'name' => 'kvm',
                    'kind' => 'kvm',
                    'type' => 'char',
                    'major' => 10,
                    'minor' => 232,
                    'mode' => '0o660',
                    'uid' => 0,
                    'gid' => 104,
                    'group' => 'kvm',
                    'ready' => true,
                    'warnings' => [],
                    'used_by' => [],
                ],
            ],
            'block' => [
                [
                    'path' => '/dev/sda',
                    'name' => 'sda',
                    'size' => 1000204886016,
                    'model' => 'WDC WD10EACS-22ZJB0',
                    'serial' => 'WD-WCASJ1664919',
                    'rotational' => true,
                    'mounts' => [],
                    'ready' => true,
                    'warnings' => [],
                    'used_by' => [],
                ],
            ],
            'gpus' => [
                [
                    'address' => '0000:03:00.0',
                    'name' => 'NVIDIA Corporation GK107 [NVS 510]',
                    'vendor_id' => '10de',
                    'device_id' => '0e08',
                    'vendor' => 'NVIDIA Corporation',
                    'device' => 'GK107 [NVS 510]',
                    'driver' => 'nvidia',
                    'iommu_group' => '57',
                    'drm_nodes' => ['/dev/dri/card0'],
                    'nvidia' => null,
                    'ready' => false,
                    'warnings' => [],
                    'used_by' => [],
                ],
            ],
            'nvidia' => [
                'available' => false,
                'error' => 'No devices were found',
                'devices' => [],
            ],
            'docker' => [
                'available' => true,
                'error' => null,
                'runtimes' => ['runc'],
                'nvidia_runtime' => false,
                'swarm' => 'active',
            ],
        ];
    }
}
