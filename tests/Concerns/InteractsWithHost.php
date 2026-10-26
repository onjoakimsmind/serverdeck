<?php

namespace Tests\Concerns;

use App\Services\Host\HostHealthService;
use App\Services\Host\HostMetricsService;
use Mockery\MockInterface;

trait InteractsWithHost
{
    /**
     * @return array<string, mixed>
     */
    protected function hostIdentity(): array
    {
        return [
            'hostname' => 'pve',
            'os' => 'Ubuntu 26.04.1 LTS',
            'os_id' => 'ubuntu',
            'os_version' => '26.04',
            'kernel' => '7.0.0-38-generic',
            'arch' => 'x86_64',
            'cpu_model' => 'AMD Ryzen 9 5950X 16-Core Processor',
            'cpu_threads' => 32,
            'cpu_cores' => 16,
            'sockets' => 1,
            'virtualization' => 'VT-x / AMD-V',
            'uptime_seconds' => 86400,
            'booted_at' => now()->subDay()->toIso8601String(),
            'timezone' => 'UTC',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function hostSnapshot(): array
    {
        return [
            'host' => $this->hostIdentity(),
            'cpu' => [
                'usage' => 12.5,
                'per_core' => [10.0, 15.0],
                'load_1' => 0.5,
                'load_5' => 0.4,
                'load_15' => 0.3,
            ],
            'memory' => [
                'total' => 32212254720,
                'used' => 12884901888,
                'available' => 19327352832,
                'free' => 10737418240,
                'buffers' => 0,
                'cached' => 0,
                'shared' => 0,
                'percent' => 40.0,
                'swap_total' => 0,
                'swap_used' => 0,
                'swap_percent' => 0,
            ],
            'network' => [
                'interfaces' => [],
                'rx_bytes' => 0,
                'tx_bytes' => 0,
                'rx_rate' => 0,
                'tx_rate' => 0,
            ],
            'disks' => [
                'root' => [
                    'mount' => '/',
                    'used' => 214748364800,
                    'size' => 536870912000,
                    'percent' => 40.0,
                ],
                'all' => [],
            ],
            'gpu' => [
                'available' => false,
                'devices' => [],
                'error' => 'No GPU telemetry available.',
            ],
            'processes' => 250,
            'temperature' => null,
            'sampled_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function healthReport(): array
    {
        return [
            'checks' => [],
            'summary' => [
                'total' => 0,
                'ok' => 0,
                'warning' => 0,
                'critical' => 0,
                'info' => 0,
                'overall' => 'ok',
                'attention' => 0,
                'active' => [],
                'resources' => [
                    'docker' => ['all' => 4, 'running' => 3, 'stopped' => 1, 'unhealthy' => 0],
                    'incus' => ['all' => 3, 'running' => 3, 'stopped' => 0],
                    'zfs' => ['pools' => 1, 'datasets' => 5, 'degraded' => 0],
                    'systemd' => ['all' => 120, 'running' => 100, 'failed' => 0],
                    'smart' => ['total' => 8, 'ok' => 7, 'warning' => 0, 'critical' => 1, 'info' => 0],
                    'updates' => [
                        'total' => 2,
                        'planned' => 2,
                        'security' => 1,
                        'new_packages' => 1,
                        'held_back' => 0,
                        'reboot_required' => false,
                    ],
                ],
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    protected function fakeMetrics(): void
    {
        $this->mock(HostMetricsService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('identity')->andReturn($this->hostIdentity());
            $mock->shouldReceive('snapshot')->andReturn($this->hostSnapshot());
        });
    }

    protected function fakeHealth(): void
    {
        $this->mock(HostHealthService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('report')->andReturn($this->healthReport());
            $mock->shouldReceive('summary')->andReturn($this->healthReport()['summary']);
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });
    }

    protected function fakeHostTelemetry(): void
    {
        $this->fakeMetrics();
        $this->fakeHealth();
    }

    /**
     * @return array<string, mixed>
     */
    protected function dockerOverview(): array
    {
        return [
            'available' => true,
            'error' => null,
            'containers' => [],
            'counts' => ['all' => 4, 'running' => 3, 'stopped' => 1, 'unhealthy' => 0],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function incusOverview(): array
    {
        return [
            'available' => true,
            'error' => null,
            'instances' => [],
            'counts' => ['all' => 3, 'running' => 3, 'stopped' => 0],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function zfsOverview(): array
    {
        return [
            'available' => true,
            'error' => null,
            'pools' => [],
            'datasets' => [],
            'counts' => ['pools' => 1, 'datasets' => 5, 'degraded' => 0],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function systemdOverview(): array
    {
        return [
            'available' => true,
            'error' => null,
            'units' => [],
            'counts' => ['all' => 120, 'running' => 100, 'failed' => 0, 'inactive' => 20],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function smartOverview(): array
    {
        return [
            'available' => true,
            'error' => null,
            'devices' => [
                [
                    'name' => 'sda',
                    'path' => '/dev/sda',
                    'size' => 1000204886016,
                    'model' => 'WDC WD10EACS-22ZJB0',
                    'serial' => 'WD-WCASJ1664919',
                    'rotational' => true,
                    'transport' => 'sas',
                    'mounts' => [],
                    'zfs_pools' => ['storage'],
                    'root_disk' => false,
                    'smart_supported' => true,
                    'smart_enabled' => true,
                    'health_passed' => true,
                    'temperature' => 36,
                    'power_on_hours' => 61319,
                    'power_cycles' => 120,
                    'firmware' => '01.01A01',
                    'protocol' => 'ATA',
                    'reallocated_sectors' => 0,
                    'pending_sectors' => 0,
                    'uncorrectable_sectors' => 0,
                    'crc_errors' => 0,
                    'wear_leveling' => null,
                    'wear_leveling_value' => null,
                    'percentage_used' => null,
                    'media_errors' => null,
                    'error_log_count' => 0,
                    'self_test' => null,
                    'messages' => [],
                    'error' => null,
                    'status' => 'ok',
                    'reasons' => [],
                ],
                [
                    'name' => 'sdc',
                    'path' => '/dev/sdc',
                    'size' => 1000204886016,
                    'model' => 'ST31000524AS',
                    'serial' => '9VPGJ4WZ',
                    'rotational' => true,
                    'transport' => 'sas',
                    'mounts' => [],
                    'zfs_pools' => ['storage'],
                    'root_disk' => false,
                    'smart_supported' => true,
                    'smart_enabled' => true,
                    'health_passed' => true,
                    'temperature' => 33,
                    'power_on_hours' => 51540,
                    'power_cycles' => 90,
                    'firmware' => 'JC45',
                    'protocol' => 'ATA',
                    'reallocated_sectors' => 1030,
                    'pending_sectors' => 294,
                    'uncorrectable_sectors' => 294,
                    'crc_errors' => 0,
                    'wear_leveling' => null,
                    'wear_leveling_value' => null,
                    'percentage_used' => null,
                    'media_errors' => null,
                    'error_log_count' => 0,
                    'self_test' => [
                        'status' => 'Completed without error',
                        'type' => 'Short offline',
                        'remaining_percent' => 0,
                        'lifetime_hours' => 51000,
                        'in_progress' => false,
                        'failed' => false,
                    ],
                    'messages' => [],
                    'error' => null,
                    'status' => 'critical',
                    'reasons' => ['294 pending sector(s)', '294 uncorrectable sector(s)', '1030 reallocated sector(s)'],
                ],
            ],
            'counts' => ['total' => 2, 'ok' => 1, 'warning' => 0, 'critical' => 1, 'info' => 0],
            'attention' => 1,
            'max_temperature' => 36,
            'scanned_at' => now()->timestamp,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function updatesOverview(): array
    {
        return [
            'available' => true,
            'error' => null,
            'manager' => 'apt',
            'manager_label' => 'APT',
            'security_supported' => true,
            'security_note' => null,
            'packages' => [
                [
                    'name' => 'xxd',
                    'current_version' => null,
                    'candidate_version' => '2:9.1.2141-1ubuntu4.9',
                    'repositories' => ['Ubuntu:26.04/resolute-updates', 'Ubuntu:26.04/resolute-security'],
                    'repository' => 'Ubuntu:26.04/resolute-updates, Ubuntu:26.04/resolute-security',
                    'architecture' => 'amd64',
                    'security' => true,
                    'is_new' => true,
                ],
                [
                    'name' => 'ubuntu-server-minimal',
                    'current_version' => '1.570.3',
                    'candidate_version' => '1.570.4',
                    'repositories' => ['Ubuntu:26.04/resolute-updates'],
                    'repository' => 'Ubuntu:26.04/resolute-updates',
                    'architecture' => 'amd64',
                    'security' => false,
                    'is_new' => false,
                ],
            ],
            'held_back' => [],
            'counts' => [
                'total' => 2,
                'planned' => 2,
                'security' => 1,
                'new_packages' => 1,
                'held_back' => 0,
            ],
            'reboot_required' => false,
            'reboot_packages' => [],
            'running_kernel' => '7.0.0-38-generic',
            'latest_kernel' => '7.0.0-38-generic',
            'kernel_update_pending' => false,
            'lists_updated_at' => now()->subHour()->timestamp,
            'last_upgrade_at' => now()->subDay()->timestamp,
            'unattended_upgrades_active' => true,
            'simulated_at' => now()->timestamp,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function backupInventory(): array
    {
        return [
            'available' => true,
            'error' => null,
            'datasets' => [
                ['name' => 'storage', 'mountpoint' => '/storage', 'used' => 1024, 'avail' => 2048],
            ],
            'instances' => [
                ['name' => 'hermes', 'type' => 'container', 'status' => 'running'],
            ],
            'zfs_snapshots' => [
                [
                    'kind' => 'zfs',
                    'target' => 'storage',
                    'name' => 'serverdeck-job1-20261026-030000',
                    'full' => 'storage@serverdeck-job1-20261026-030000',
                    'used' => 1024,
                    'created_at' => now()->timestamp,
                    'refer' => 1024,
                ],
            ],
            'incus_snapshots' => [],
            'zfs_snapshot_count' => 1,
            'incus_snapshot_count' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function backupOverview(): array
    {
        return [
            'jobs' => [],
            'runs' => [],
            'inventory' => $this->backupInventory(),
            'counts' => ['jobs' => 0, 'enabled' => 0, 'failing' => 0, 'snapshots' => 1],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function incusImage(string $type = 'container'): array
    {
        return [
            'fingerprint' => str_repeat('a', 64),
            'type' => $type,
            'os' => 'Ubuntu',
            'release' => '24.04',
            'variant' => 'default',
            'description' => 'Ubuntu 24.04 LTS',
            'architecture' => 'x86_64',
            'size' => 139408032,
            'label' => 'Ubuntu 24.04 · default',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function incusDetail(string $type = 'container'): array
    {
        return [
            'name' => 'sd-config-test',
            'type' => $type,
            'status' => 'stopped',
            'architecture' => 'x86_64',
            'description' => 'Test instance',
            'profiles' => ['default'],
            'image' => 'Ubuntu 24.04 LTS',
            'config' => [
                'limits.cpu' => '2',
                'limits.memory' => '1GiB',
                'boot.autostart' => 'true',
            ],
            'devices' => [
                'eth0' => ['type' => 'nic', 'nictype' => 'bridged', 'parent' => 'br0', 'vlan' => '42'],
                'root' => ['type' => 'disk', 'path' => '/', 'pool' => 'default', 'size' => '10GiB'],
            ],
            'expanded_devices' => [
                'eth0' => ['type' => 'nic', 'nictype' => 'bridged', 'parent' => 'br0', 'vlan' => '42'],
                'root' => ['type' => 'disk', 'path' => '/', 'pool' => 'default', 'size' => '10GiB'],
            ],
            'root_size' => '10GiB',
            'networks' => [
                ['name' => 'incusbr0', 'type' => 'bridge', 'managed' => true, 'description' => '', 'ipv4_address' => '10.10.0.1/24', 'ipv6_address' => ''],
                ['name' => 'br0', 'type' => 'bridge', 'managed' => false, 'description' => '', 'ipv4_address' => '', 'ipv6_address' => ''],
            ],
            'bridges' => [
                ['name' => 'br0', 'description' => '', 'ipv4_address' => ''],
            ],
            'storage_pools' => [
                ['name' => 'default', 'driver' => 'zfs', 'description' => ''],
            ],
            'storage_volumes' => [
                ['pool' => 'default', 'name' => 'data', 'content_type' => 'filesystem', 'size' => '10GiB', 'used_by' => 0, 'available' => true],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function incusRemoteImage(string $type = 'container'): array
    {
        $entry = [
            'alias' => 'ubuntu/24.04',
            'ref' => 'images:ubuntu/24.04',
            'os' => 'Ubuntu',
            'release' => '24.04',
            'variant' => 'default',
            'description' => 'Ubuntu 24.04 LTS',
            'architecture' => 'x86_64',
            'label' => 'Ubuntu 24.04 · default',
            'container' => null,
            'virtual_machine' => null,
        ];

        $entry[$type === 'virtual-machine' ? 'virtual_machine' : 'container'] = [
            'fingerprint' => str_repeat('b', 64),
            'size' => 139408032,
        ];

        return $entry;
    }
}
