<?php

namespace App\Services\Host;

use App\Exceptions\HostCommandException;
use Illuminate\Support\Facades\Cache;

class UpdateService
{
    private const CACHE_KEY = 'host.updates.status';

    private const CACHE_SECONDS = 120;

    public function __construct(private readonly PrivilegedHost $host) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(bool $refresh = false): array
    {
        if ($refresh) {
            $this->forget();
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            try {
                return ['available' => true, 'error' => null] + $this->host->run('packages.status', [], 180);
            } catch (HostCommandException $exception) {
                return [
                    'available' => false,
                    'error' => $exception->getMessage(),
                    'manager' => 'unknown',
                    'manager_label' => 'Packages',
                    'security_supported' => true,
                    'security_note' => null,
                    'packages' => [],
                    'held_back' => [],
                    'counts' => ['total' => 0, 'planned' => 0, 'security' => 0, 'new_packages' => 0, 'held_back' => 0],
                    'reboot_required' => false,
                    'reboot_packages' => [],
                    'running_kernel' => null,
                    'latest_kernel' => null,
                    'kernel_update_pending' => false,
                    'lists_updated_at' => null,
                    'last_upgrade_at' => null,
                    'unattended_upgrades_active' => false,
                    'simulated_at' => null,
                ];
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function refresh(): array
    {
        $this->host->run('packages.refresh', [], 300);

        return $this->overview(true);
    }

    /**
     * @return array{upgrade: array<string, mixed>, status: array<string, mixed>}
     */
    public function upgrade(): array
    {
        $result = $this->host->run('packages.upgrade', [], 900);

        return [
            'upgrade' => $result,
            'status' => $this->overview(true),
        ];
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
