<?php

namespace App\Services\Host;

use App\Exceptions\HostCommandException;

class SystemdService
{
    public function __construct(private readonly PrivilegedHost $host) {}

    public function overview(): array
    {
        try {
            $data = $this->host->run('systemd.list', [], 30);
        } catch (HostCommandException $exception) {
            return [
                'available' => false,
                'error' => $exception->getMessage(),
                'units' => [],
                'counts' => ['all' => 0, 'running' => 0, 'failed' => 0, 'inactive' => 0],
            ];
        }

        $units = collect($data['units'] ?? [])
            ->map(fn (array $unit) => [
                'unit' => (string) ($unit['unit'] ?? ''),
                'load' => (string) ($unit['load'] ?? ''),
                'active' => (string) ($unit['active'] ?? ''),
                'sub' => (string) ($unit['sub'] ?? ''),
                'description' => (string) ($unit['description'] ?? ''),
            ])
            ->sortBy(fn ($unit) => [
                $unit['active'] === 'failed' ? 0 : ($unit['active'] === 'active' ? 1 : 2),
                $unit['unit'],
            ])
            ->values();

        return [
            'available' => true,
            'error' => null,
            'units' => $units,
            'counts' => [
                'all' => $units->count(),
                'running' => $units->where('active', 'active')->count(),
                'failed' => $units->where('active', 'failed')->count(),
                'inactive' => $units->where('active', 'inactive')->count(),
            ],
        ];
    }

    public function action(string $action, string $unit): array
    {
        return $this->host->run('systemd.action', ['action' => $action, 'unit' => $unit], 120);
    }
}
