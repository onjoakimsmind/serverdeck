<?php

namespace App\Services\Host;

use App\Exceptions\HostCommandException;

class ZfsService
{
    public function __construct(private readonly PrivilegedHost $host) {}

    public function overview(): array
    {
        try {
            $data = $this->host->run('zfs.status', [], 30);
        } catch (HostCommandException $exception) {
            return [
                'available' => false,
                'error' => $exception->getMessage(),
                'pools' => [],
                'datasets' => [],
                'status' => '',
                'version' => null,
                'counts' => ['pools' => 0, 'datasets' => 0, 'degraded' => 0],
            ];
        }

        $pools = collect($data['pools'] ?? [])
            ->map(fn (array $pool) => [
                ...$pool,
                'capacity_percent' => (float) rtrim((string) ($pool['capacity'] ?? '0'), '%'),
                'fragmentation_percent' => (float) rtrim((string) ($pool['fragmentation'] ?? '0'), '%'),
            ])
            ->values();

        $datasets = collect($data['datasets'] ?? [])
            ->sortBy('name')
            ->values();

        return [
            'available' => true,
            'error' => null,
            'pools' => $pools,
            'datasets' => $datasets,
            'status' => (string) ($data['status'] ?? ''),
            'version' => $data['version'] ?? null,
            'counts' => [
                'pools' => $pools->count(),
                'datasets' => $datasets->count(),
                'degraded' => $pools->where('health', '!=', 'ONLINE')->count(),
            ],
        ];
    }

    public function action(array $payload): array
    {
        return $this->host->run('zfs.action', $payload, 150);
    }
}
