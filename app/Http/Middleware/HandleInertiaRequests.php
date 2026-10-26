<?php

namespace App\Http\Middleware;

use App\Models\AlertState;
use App\Services\Host\HostHealthService;
use App\Services\Host\HostMetricsService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name', 'ServerDeck'),
                'version' => '2.0',
            ],
            'host' => fn () => app(HostMetricsService::class)->identity(),
            'auth' => $user ? [
                'id' => $user->id,
                'username' => $user->username,
                'name' => $user->name,
                'email' => $user->email,
                'title' => $user->title,
                'role' => $user->role->value,
                'role_label' => $user->role->label(),
                'initials' => $user->initials,
                'last_seen_at' => $user->last_seen_at?->toIso8601String(),
                'is_active' => $user->is_active,
                'linux_groups' => $user->linux_groups ?? [],
            ] : null,
            'permissions' => [
                'operate' => $user?->canOperate() ?? false,
                'manageUsers' => $user?->isAdmin() ?? false,
                'viewAudit' => $user?->isAdmin() ?? false,
                'useTerminal' => $user?->canUseTerminal() ?? false,
            ],
            'navigation' => fn () => $user ? $this->navigation() : $this->emptyNavigation(),
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }

    private function navigation(): array
    {
        $summary = app(HostHealthService::class)->summary();

        return [
            'health' => $summary,
            'counts' => [
                'health' => $summary['attention'] ?? 0,
                'critical_health' => $summary['critical'] ?? 0,
                'docker_running' => $summary['resources']['docker']['running'] ?? 0,
                'docker_total' => $summary['resources']['docker']['all'] ?? 0,
                'incus_running' => $summary['resources']['incus']['running'] ?? 0,
                'incus_total' => $summary['resources']['incus']['all'] ?? 0,
                'failed_services' => $summary['resources']['systemd']['failed'] ?? 0,
                'pools' => $summary['resources']['zfs']['pools'] ?? 0,
                'smart_attention' => ($summary['resources']['smart']['warning'] ?? 0) + ($summary['resources']['smart']['critical'] ?? 0),
                'smart_critical' => $summary['resources']['smart']['critical'] ?? 0,
                'updates' => $summary['resources']['updates']['total'] ?? 0,
                'security_updates' => $summary['resources']['updates']['security'] ?? 0,
                'reboot_required' => $summary['resources']['updates']['reboot_required'] ?? false,
                'alerts' => AlertState::query()
                    ->whereIn('last_status', ['warning', 'critical'])
                    ->whereNull('resolved_at')
                    ->count(),
            ],
        ];
    }

    private function emptyNavigation(): array
    {
        return [
            'health' => [
                'overall' => 'ok',
                'attention' => 0,
                'critical' => 0,
                'active' => [],
                'resources' => [
                    'docker' => ['running' => 0, 'all' => 0],
                    'incus' => ['running' => 0, 'all' => 0],
                    'zfs' => ['pools' => 0],
                    'systemd' => ['failed' => 0],
                ],
            ],
            'counts' => [
                'health' => 0,
                'critical_health' => 0,
                'docker_running' => 0,
                'docker_total' => 0,
                'incus_running' => 0,
                'incus_total' => 0,
                'failed_services' => 0,
                'pools' => 0,
                'smart_attention' => 0,
                'smart_critical' => 0,
                'updates' => 0,
                'security_updates' => 0,
                'reboot_required' => false,
                'alerts' => 0,
            ],
        ];
    }
}
