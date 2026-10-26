<?php

namespace App\Services\Host;

use Illuminate\Support\Facades\Cache;

class HostHealthService
{
    public function __construct(
        private readonly HostMetricsService $metrics,
        private readonly DockerService $docker,
        private readonly IncusService $incus,
        private readonly ZfsService $zfs,
        private readonly SystemdService $systemd,
        private readonly SmartService $smart,
        private readonly UpdateService $updates,
    ) {}

    /**
     * @param  array{metrics: array<string, mixed>, docker: array<string, mixed>, incus: array<string, mixed>, zfs: array<string, mixed>, systemd: array<string, mixed>}|null  $telemetry
     */
    public function report(?array $telemetry = null): array
    {
        if ($telemetry !== null) {
            return $this->build($telemetry);
        }

        return Cache::remember('host.health.report', 20, fn () => $this->build());
    }

    public function checks(): array
    {
        return $this->report()['checks'];
    }

    public function summary(): array
    {
        return $this->report()['summary'];
    }

    public function forget(): void
    {
        Cache::forget('host.health.report');
        Cache::forget('host.inventory');
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
        $smart = $telemetry['smart'] ?? $this->smart->overview();
        $updates = $telemetry['updates'] ?? $this->updates->overview();

        $checks = [];

        $checks[] = $this->check(
            'cpu',
            'CPU pressure',
            $metrics['cpu']['usage'],
            $metrics['cpu']['usage'] >= 95 ? 'critical' : ($metrics['cpu']['usage'] >= 85 ? 'warning' : 'ok'),
            sprintf('%s%% used across %d threads · load %s / %s / %s', number_format($metrics['cpu']['usage'], 1), $metrics['host']['cpu_threads'], $metrics['cpu']['load_1'], $metrics['cpu']['load_5'], $metrics['cpu']['load_15']),
            '/system',
        );

        $checks[] = $this->check(
            'memory',
            'Memory pressure',
            $metrics['memory']['percent'],
            $metrics['memory']['percent'] >= 95 ? 'critical' : ($metrics['memory']['percent'] >= 85 ? 'warning' : 'ok'),
            sprintf('%s used of %s · %s available', $this->bytes($metrics['memory']['used']), $this->bytes($metrics['memory']['total']), $this->bytes($metrics['memory']['available'])),
            '/system#memory',
        );

        $root = $metrics['disks']['root'];
        $checks[] = $this->check(
            'disk',
            'Root filesystem',
            $root['percent'] ?? 0,
            ($root['percent'] ?? 0) >= 95 ? 'critical' : (($root['percent'] ?? 0) >= 85 ? 'warning' : 'ok'),
            $root
                ? sprintf('%s used of %s on %s', $this->bytes($root['used']), $this->bytes($root['size']), $root['mount'])
                : 'Root filesystem usage is unavailable.',
            '/storage',
        );

        if (($metrics['memory']['swap_total'] ?? 0) > 0) {
            $checks[] = $this->check(
                'swap',
                'Swap utilisation',
                $metrics['memory']['swap_percent'],
                $metrics['memory']['swap_percent'] >= 95 ? 'critical' : ($metrics['memory']['swap_percent'] >= 80 ? 'warning' : 'ok'),
                sprintf('%s used of %s', $this->bytes($metrics['memory']['swap_used']), $this->bytes($metrics['memory']['swap_total'])),
                '/system#memory',
            );
        }

        $checks[] = $this->check(
            'systemd',
            'Failed systemd services',
            $systemd['counts']['failed'],
            $systemd['counts']['failed'] > 0 ? 'warning' : 'ok',
            $systemd['available']
                ? sprintf('%d failed · %d active · %d total units', $systemd['counts']['failed'], $systemd['counts']['running'], $systemd['counts']['all'])
                : 'systemd is unavailable: '.$systemd['error'],
            '/services?filter=failed',
        );

        $checks[] = $this->check(
            'zfs',
            'ZFS pool health',
            $zfs['counts']['degraded'],
            $zfs['counts']['degraded'] > 0 ? 'critical' : 'ok',
            $zfs['available']
                ? sprintf('%d pools · %d datasets · %s', $zfs['counts']['pools'], $zfs['counts']['datasets'], $zfs['counts']['degraded'] ? 'attention required' : 'all ONLINE')
                : 'ZFS is unavailable: '.$zfs['error'],
            '/storage',
        );

        $smartCounts = $smart['counts'] ?? ['total' => 0, 'ok' => 0, 'warning' => 0, 'critical' => 0, 'info' => 0];
        $smartAttention = ($smartCounts['warning'] ?? 0) + ($smartCounts['critical'] ?? 0);
        $smartStatus = ! ($smart['available'] ?? false)
            ? 'info'
            : (($smartCounts['critical'] ?? 0) > 0 ? 'critical' : ($smartAttention > 0 ? 'warning' : 'ok'));
        $smartDevices = collect($smart['devices'] ?? [])
            ->whereIn('status', ['warning', 'critical'])
            ->pluck('name')
            ->join(', ');
        $smartDetail = ! ($smart['available'] ?? false)
            ? 'SMART is unavailable: '.($smart['error'] ?? 'unknown error')
            : sprintf(
                '%d disks · %s%s',
                $smartCounts['total'] ?? 0,
                $smartStatus === 'ok' ? 'all healthy' : "{$smartAttention} need attention ({$smartDevices})",
                ($smart['max_temperature'] ?? null) !== null ? ' · hottest '.(int) $smart['max_temperature'].'°C' : '',
            );

        $checks[] = $this->check(
            'smart',
            'SMART disk health',
            $smartAttention,
            $smartStatus,
            $smartDetail,
            '/disks',
        );

        $updateCounts = $updates['counts'] ?? ['total' => 0, 'security' => 0, 'held_back' => 0];
        $rebootRequired = (bool) ($updates['reboot_required'] ?? false);
        $updateStatus = ! ($updates['available'] ?? false)
            ? 'info'
            : ($rebootRequired ? 'warning' : (($updateCounts['security'] ?? 0) > 0 ? 'warning' : (($updateCounts['total'] ?? 0) > 0 ? 'info' : 'ok')));
        $updateDetail = ! ($updates['available'] ?? false)
            ? 'Updates are unavailable: '.($updates['error'] ?? 'unknown error')
            : sprintf(
                '%d update%s · %d security%s',
                $updateCounts['total'] ?? 0,
                ($updateCounts['total'] ?? 0) === 1 ? '' : 's',
                $updateCounts['security'] ?? 0,
                $rebootRequired ? ' · reboot required' : '',
            );

        $checks[] = $this->check(
            'updates',
            'System updates',
            $updateCounts['total'] ?? 0,
            $updateStatus,
            $updateDetail,
            '/updates',
        );

        $unhealthy = $docker['counts']['unhealthy'] ?? 0;
        $checks[] = $this->check(
            'docker',
            'Docker containers',
            $unhealthy,
            $unhealthy > 0 ? 'critical' : 'ok',
            $docker['available']
                ? sprintf('%d running · %d stopped · %d unhealthy', $docker['counts']['running'], $docker['counts']['stopped'], $unhealthy)
                : 'Docker is unavailable: '.$docker['error'],
            '/docker',
        );

        $checks[] = $this->check(
            'incus',
            'Incus instances',
            $incus['counts']['all'] - $incus['counts']['running'],
            $incus['available'] ? 'ok' : 'warning',
            $incus['available']
                ? sprintf('%d running · %d total', $incus['counts']['running'], $incus['counts']['all'])
                : 'Incus is unavailable: '.$incus['error'],
            '/incus',
        );

        $checks[] = $this->check(
            'gpu',
            'GPU telemetry',
            $metrics['gpu']['available'] ? 1 : 0,
            $metrics['gpu']['available'] ? 'ok' : 'info',
            $metrics['gpu']['available']
                ? collect($metrics['gpu']['devices'])->pluck('name')->filter()->join(', ')
                : ($metrics['gpu']['error'] ?? 'No GPU telemetry available.'),
            '/system#gpu',
        );

        if ($metrics['temperature']) {
            $checks[] = $this->check(
                'temperature',
                'Host temperature',
                $metrics['temperature']['celsius'],
                $metrics['temperature']['celsius'] >= 90 ? 'critical' : ($metrics['temperature']['celsius'] >= 80 ? 'warning' : 'ok'),
                sprintf('%s · %s°C', $metrics['temperature']['label'], $metrics['temperature']['celsius']),
                '/system',
            );
        }

        $counts = [
            'total' => count($checks),
            'ok' => count(array_filter($checks, fn ($check) => $check['status'] === 'ok')),
            'warning' => count(array_filter($checks, fn ($check) => $check['status'] === 'warning')),
            'critical' => count(array_filter($checks, fn ($check) => $check['status'] === 'critical')),
            'info' => count(array_filter($checks, fn ($check) => $check['status'] === 'info')),
        ];

        $overall = $counts['critical'] > 0 ? 'critical' : ($counts['warning'] > 0 ? 'warning' : 'ok');
        $active = array_values(array_filter($checks, fn ($check) => in_array($check['status'], ['critical', 'warning'], true)));

        return [
            'checks' => $checks,
            'summary' => [
                ...$counts,
                'overall' => $overall,
                'attention' => count($active),
                'active' => array_slice($active, 0, 4),
                'resources' => [
                    'docker' => $docker['counts'],
                    'incus' => $incus['counts'],
                    'zfs' => $zfs['counts'],
                    'systemd' => $systemd['counts'],
                    'smart' => $smart['counts'] ?? ['total' => 0, 'ok' => 0, 'warning' => 0, 'critical' => 0, 'info' => 0],
                    'updates' => [
                        ...($updateCounts ?? []),
                        'reboot_required' => $rebootRequired ?? false,
                    ],
                ],
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function check(string $key, string $title, float|int $value, string $status, string $detail, string $href): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'value' => $value,
            'status' => $status,
            'detail' => $detail,
            'href' => $href,
        ];
    }

    private function bytes(int|float|null $bytes): string
    {
        $bytes = (float) ($bytes ?? 0);
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return round($bytes / (1024 ** $power), 1).' '.$units[$power];
    }
}
