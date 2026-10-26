<?php

namespace App\Services\Host;

use App\Exceptions\HostCommandException;
use Illuminate\Support\Facades\Cache;

class SmartService
{
    private const CACHE_KEY = 'host.smart.scan';

    private const CACHE_SECONDS = 300;

    public function __construct(private readonly PrivilegedHost $host) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            try {
                $data = $this->host->run('smart.scan', [], 120);
            } catch (HostCommandException $exception) {
                return [
                    'available' => false,
                    'error' => $exception->getMessage(),
                    'devices' => [],
                    'counts' => ['total' => 0, 'ok' => 0, 'warning' => 0, 'critical' => 0, 'info' => 0],
                    'attention' => 0,
                    'max_temperature' => null,
                    'scanned_at' => null,
                ];
            }

            // Keep this payload cache-safe: Laravel's cache unserializes with
            // object support disabled, so a Collection would come back as an
            // incomplete class and break the disks page.
            $devices = collect($data['devices'] ?? [])
                ->sortBy('name')
                ->values()
                ->all();

            $counts = [
                'total' => count($devices),
                'ok' => collect($devices)->where('status', 'ok')->count(),
                'warning' => collect($devices)->where('status', 'warning')->count(),
                'critical' => collect($devices)->where('status', 'critical')->count(),
                'info' => collect($devices)->where('status', 'info')->count(),
            ];

            return [
                'available' => true,
                'error' => null,
                'devices' => $devices,
                'counts' => $counts,
                'attention' => $counts['warning'] + $counts['critical'],
                'max_temperature' => collect($devices)->pluck('temperature')->filter(fn ($value) => $value !== null)->max(),
                'scanned_at' => $data['scanned_at'] ?? null,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function startSelfTest(string $device, string $mode): array
    {
        return $this->host->run('smart.selftest', [
            'device' => $device,
            'mode' => $mode,
        ], 90);
    }

    /**
     * @return array<string, mixed>
     */
    public function abortSelfTest(string $device): array
    {
        return $this->host->run('smart.selftest_abort', [
            'device' => $device,
        ], 90);
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
