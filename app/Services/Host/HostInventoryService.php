<?php

namespace App\Services\Host;

use Illuminate\Support\Facades\Cache;

class HostInventoryService
{
    public function __construct(
        private readonly HostMetricsService $metrics,
        private readonly DockerService $docker,
        private readonly IncusService $incus,
        private readonly ZfsService $zfs,
        private readonly SystemdService $systemd,
    ) {}

    /**
     * @param  array{metrics: array<string, mixed>, docker: array<string, mixed>, incus: array<string, mixed>, zfs: array<string, mixed>, systemd: array<string, mixed>}|null  $telemetry
     */
    public function overview(?array $telemetry = null): array
    {
        if ($telemetry !== null) {
            return $this->build($telemetry);
        }

        return Cache::remember('host.inventory', 45, fn () => $this->build());
    }

    /**
     * @param  array{metrics: array<string, mixed>, docker: array<string, mixed>, incus: array<string, mixed>, zfs: array<string, mixed>, systemd: array<string, mixed>}|null  $telemetry
     */
    private function build(?array $telemetry = null): array
    {
        $metrics = $telemetry['metrics'] ?? $this->metrics->snapshot();
        $docker = $telemetry['docker'] ?? $this->docker->overview(false);
        $incus = $telemetry['incus'] ?? $this->incus->overview();
        $zfs = $telemetry['zfs'] ?? $this->zfs->overview();
        $systemd = $telemetry['systemd'] ?? $this->systemd->overview();

        $units = collect($systemd['units'])->keyBy('unit');
        $dockerNames = collect($docker['containers'])->map(fn ($container) => strtolower($container['name'].' '.$container['image']));
        $incusNames = collect($incus['instances'])->map(fn ($instance) => strtolower($instance['name'].' '.$instance['image']));
        $dokployContainer = $dockerNames->first(fn ($name) => str_contains($name, 'dokploy'));

        $integrations = [
            [
                'key' => 'docker',
                'name' => 'Docker',
                'category' => 'Containers',
                'status' => $docker['available'] ? 'connected' : 'unavailable',
                'detail' => $docker['available']
                    ? sprintf('Engine %s · %d running of %d', $docker['info']['version'] ?? 'unknown', $docker['counts']['running'], $docker['counts']['all'])
                    : $docker['error'],
                'href' => '/docker',
            ],
            [
                'key' => 'incus',
                'name' => 'Incus',
                'category' => 'Virtualisation',
                'status' => $incus['available'] ? 'connected' : 'unavailable',
                'detail' => $incus['available']
                    ? sprintf('%d running · %d total instances', $incus['counts']['running'], $incus['counts']['all'])
                    : $incus['error'],
                'href' => '/incus',
            ],
            [
                'key' => 'zfs',
                'name' => 'OpenZFS',
                'category' => 'Storage',
                'status' => $zfs['available'] ? ($zfs['counts']['degraded'] ? 'attention' : 'connected') : 'unavailable',
                'detail' => $zfs['available']
                    ? sprintf('%d pools · %d datasets · %s', $zfs['counts']['pools'], $zfs['counts']['datasets'], $zfs['counts']['degraded'] ? 'degraded' : 'healthy')
                    : $zfs['error'],
                'href' => '/storage',
            ],
            [
                'key' => 'nvidia',
                'name' => 'NVIDIA',
                'category' => 'Acceleration',
                'status' => $metrics['gpu']['available'] ? 'connected' : 'attention',
                'detail' => $metrics['gpu']['available']
                    ? collect($metrics['gpu']['devices'])->pluck('name')->filter()->join(', ')
                    : ($metrics['gpu']['error'] ?? 'Driver unavailable'),
                'href' => '/system#gpu',
            ],
            [
                'key' => 'dokploy',
                'name' => 'Dokploy',
                'category' => 'PaaS',
                'status' => ($dokployContainer || is_dir('/etc/dokploy')) ? 'connected' : 'not-installed',
                'detail' => $dokployContainer
                    ? 'Dokploy container detected'
                    : (is_dir('/etc/dokploy') ? 'Configuration found at /etc/dokploy' : 'Not installed on this host'),
                'href' => '/dokploy',
            ],
            [
                'key' => 'tailscale',
                'name' => 'Tailscale',
                'category' => 'Networking',
                'status' => (is_executable('/usr/bin/tailscale') || $incusNames->contains(fn ($name) => str_contains($name, 'tailscale'))) ? 'connected' : 'not-installed',
                'detail' => $incusNames->contains(fn ($name) => str_contains($name, 'tailscale'))
                    ? 'Running as an Incus instance'
                    : (is_executable('/usr/bin/tailscale') ? 'Tailscale client installed' : 'Not detected'),
                'href' => '/incus',
            ],
        ];

        foreach (['nginx', 'postgresql', 'mysql', 'mariadb', 'redis', 'php8.5-fpm', 'php8.4-fpm'] as $unit) {
            $service = $units->get($unit.'.service');

            if (! $service) {
                continue;
            }

            $integrations[] = [
                'key' => $unit,
                'name' => $service['description'] ?: ucfirst($unit),
                'category' => 'Service',
                'status' => $service['active'] === 'active' ? 'connected' : 'installed',
                'detail' => $service['active'] === 'active' ? 'Active systemd service' : 'Installed · '.$service['active'],
                'href' => '/services?filter='.($service['active'] === 'active' ? 'running' : 'all'),
            ];
        }

        return [
            'integrations' => $integrations,
            'installed' => count(array_filter($integrations, fn ($item) => ! in_array($item['status'], ['not-installed', 'unavailable'], true))),
            'attention' => count(array_filter($integrations, fn ($item) => $item['status'] === 'attention')),
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
