<?php

namespace App\Services\Host;

use App\Exceptions\HostCommandException;

class DockerService
{
    public function __construct(private readonly PrivilegedHost $host) {}

    public function overview(bool $withStats = true): array
    {
        try {
            $data = $this->host->run('docker.list', ['stats' => $withStats], 45);
        } catch (HostCommandException $exception) {
            return [
                'available' => false,
                'error' => $exception->getMessage(),
                'containers' => [],
                'info' => [],
                'counts' => ['all' => 0, 'running' => 0, 'stopped' => 0, 'paused' => 0, 'unhealthy' => 0],
            ];
        }

        $containers = collect($data['containers'] ?? [])
            ->map(function (array $container) {
                $stats = $container['stats'] ?? [];
                $state = strtolower((string) ($container['State'] ?? 'unknown'));
                $health = strtolower((string) ($container['HealthStatus'] ?? ''));

                return [
                    'id' => substr((string) ($container['ID'] ?? ''), 0, 12),
                    'full_id' => (string) ($container['ID'] ?? ''),
                    'name' => ltrim((string) ($container['Names'] ?? 'unknown'), '/'),
                    'image' => (string) ($container['Image'] ?? ''),
                    'command' => (string) ($container['Command'] ?? ''),
                    'state' => $state,
                    'status' => (string) ($container['Status'] ?? ''),
                    'health' => $health,
                    'ports' => (string) ($container['Ports'] ?? ''),
                    'created' => (string) ($container['CreatedAt'] ?? ''),
                    'running_for' => (string) ($container['RunningFor'] ?? ''),
                    'mounts' => (string) ($container['Mounts'] ?? ''),
                    'networks' => (string) ($container['Networks'] ?? ''),
                    'swarm' => (bool) ($container['swarm'] ?? false),
                    'devices' => array_values(array_filter((array) ($container['devices'] ?? []), 'is_array')),
                    'gpus' => (bool) ($container['gpus'] ?? false),
                    'cpu' => $stats['cpu'] ?? null,
                    'memory' => $stats['memory'] ?? null,
                    'memory_percent' => $stats['memory_percent'] ?? null,
                    'network' => $stats['network'] ?? null,
                    'block' => $stats['block'] ?? null,
                    'pids' => $stats['pids'] ?? null,
                ];
            })
            ->sortBy([
                fn ($a, $b) => ($b['state'] === 'running') <=> ($a['state'] === 'running'),
                fn ($a, $b) => strcasecmp($a['name'], $b['name']),
            ])
            ->values();

        $info = $data['info'] ?? [];

        return [
            'available' => true,
            'error' => null,
            'containers' => $containers,
            'info' => $info,
            'counts' => [
                'all' => $containers->count(),
                'running' => $containers->where('state', 'running')->count(),
                'stopped' => $containers->whereIn('state', ['exited', 'created', 'dead'])->count(),
                'paused' => $containers->where('state', 'paused')->count(),
                'unhealthy' => $containers->where('health', 'unhealthy')->count(),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function networks(): array
    {
        try {
            $data = $this->host->run('docker.networks', [], 25);
        } catch (HostCommandException) {
            return [];
        }

        return collect($data['networks'] ?? [])
            ->map(fn (array $network) => [
                'id' => (string) ($network['id'] ?? ''),
                'name' => (string) ($network['name'] ?? ''),
                'driver' => (string) ($network['driver'] ?? ''),
                'scope' => (string) ($network['scope'] ?? ''),
                'internal' => (bool) ($network['internal'] ?? false),
                'ipv6' => (bool) ($network['ipv6'] ?? false),
                'created_at' => (string) ($network['created_at'] ?? ''),
                'subnets' => $network['subnets'] ?? [],
                'containers' => $network['containers'] ?? [],
            ])
            ->values()
            ->all();
    }

    public function action(string $action, string $container): array
    {
        return $this->host->run('docker.action', ['action' => $action, 'container' => $container], 90);
    }

    /**
     * @param  array<int, array{host: string, container: string, permissions: string}>  $devices
     */
    public function updateDevices(string $container, array $devices, bool $gpus): array
    {
        return $this->host->run('docker.device_update', [
            'container' => $container,
            'devices' => $devices,
            'gpus' => $gpus,
        ], 120);
    }

    public function logs(string $container, int $tail = 250): array
    {
        return $this->host->run('docker.logs', ['container' => $container, 'tail' => $tail], 30);
    }
}
