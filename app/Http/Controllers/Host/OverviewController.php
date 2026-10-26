<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Host\DockerService;
use App\Services\Host\HostHealthService;
use App\Services\Host\HostInventoryService;
use App\Services\Host\HostMetricsService;
use App\Services\Host\IncusService;
use App\Services\Host\SystemdService;
use App\Services\Host\ZfsService;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    public function __invoke(
        HostMetricsService $metrics,
        HostHealthService $health,
        HostInventoryService $inventory,
        DockerService $docker,
        IncusService $incus,
        ZfsService $zfs,
        SystemdService $systemd,
    ): Response {
        $snapshot = $metrics->snapshot();
        $dockerOverview = $docker->overview();
        $incusOverview = $incus->overview();
        $zfsOverview = $zfs->overview();
        $systemdOverview = $systemd->overview();

        $telemetry = [
            'metrics' => $snapshot,
            'docker' => $dockerOverview,
            'incus' => $incusOverview,
            'zfs' => $zfsOverview,
            'systemd' => $systemdOverview,
        ];

        $healthReport = $health->report($telemetry);
        $stack = $inventory->overview($telemetry);

        $root = $snapshot['disks']['root'];

        return Inertia::render('Dashboard', [
            'metrics' => $snapshot,
            'health' => $healthReport,
            'stack' => $stack,
            'docker' => $dockerOverview,
            'incus' => $incusOverview,
            'zfs' => $zfsOverview,
            'systemd' => [
                'counts' => $systemdOverview['counts'],
                'failed' => collect($systemdOverview['units'])->where('active', 'failed')->take(5)->values(),
                'notable' => collect($systemdOverview['units'])
                    ->whereIn('unit', ['nginx.service', 'php8.5-fpm.service', 'docker.service', 'incus.service', 'ssh.service', 'tailscaled.service'])
                    ->values(),
            ],
            'stats' => [
                'cpu' => $snapshot['cpu']['usage'],
                'memory' => $snapshot['memory']['percent'],
                'disk' => $root['percent'] ?? 0,
                'processes' => $snapshot['processes'],
                'uptime_seconds' => $snapshot['host']['uptime_seconds'],
                'containers_running' => $dockerOverview['counts']['running'],
                'containers_total' => $dockerOverview['counts']['all'],
                'instances_running' => $incusOverview['counts']['running'],
                'instances_total' => $incusOverview['counts']['all'],
                'pools' => $zfsOverview['counts']['pools'],
                'datasets' => $zfsOverview['counts']['datasets'],
                'failed_services' => $systemdOverview['counts']['failed'],
                'gpu_available' => $snapshot['gpu']['available'],
            ],
            'activity' => AuditLog::query()
                ->with('user:id,name,username')
                ->latest('id')
                ->limit(8)
                ->get(),
        ]);
    }
}
