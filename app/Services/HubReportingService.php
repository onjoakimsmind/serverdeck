<?php

namespace App\Services;

use App\Models\Setting;
use App\Services\Host\HostHealthService;
use App\Services\Host\HostMetricsService;
use App\Services\Host\NetworkConnectors\NetworkConnector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Opt-in, one-way status reporting to a ServerDeck Hub. See
 * docs/design/hub.md. This service only ever sends data outward; nothing
 * about it lets a Hub read anything beyond the fixed payload shape below,
 * and nothing about it lets a Hub act on this host in any way.
 */
class HubReportingService
{
    /**
     * @param  iterable<NetworkConnector>  $connectors
     */
    public function __construct(
        private readonly HostMetricsService $metrics,
        private readonly HostHealthService $health,
        private readonly iterable $connectors,
    ) {}

    public function baseUrl(): ?string
    {
        $url = Setting::get('hub.url');

        return $url ? rtrim($url, '/') : null;
    }

    public function configured(): bool
    {
        return filled($this->baseUrl()) && filled($this->token());
    }

    public function tokenHint(): ?string
    {
        $token = $this->token();

        if (! $token) {
            return null;
        }

        return strlen($token) > 8 ? '••••'.substr($token, -4) : '••••';
    }

    public function lastReportedAt(): ?string
    {
        return Setting::get('hub.last_reported_at');
    }

    public function lastStatus(): ?string
    {
        return Setting::get('hub.last_status');
    }

    /**
     * Build the status snapshot that would be sent to the Hub, without
     * sending it. Useful for previews and tests.
     *
     * @return array<string, mixed>
     */
    public function buildPayload(): array
    {
        $snapshot = $this->metrics->snapshot();
        $summary = $this->health->summary();
        $resources = $summary['resources'];
        $root = $snapshot['disks']['root'] ?? null;

        $network = [];

        foreach ($this->connectors as $connector) {
            $detected = $connector->detect();

            if ($detected !== null) {
                $network[$connector->key()] = $detected;
            }
        }

        return [
            'reported_at' => now()->toIso8601String(),
            'host' => [
                'hostname' => $snapshot['host']['hostname'],
                'os' => $snapshot['host']['os'],
                'kernel' => $snapshot['host']['kernel'],
                'uptime_seconds' => $snapshot['host']['uptime_seconds'],
                'serverdeck_version' => config('serverdeck.version'),
            ],
            'metrics' => [
                'cpu_percent' => $snapshot['cpu']['usage'],
                'memory_percent' => $snapshot['memory']['percent'],
                'root_disk_percent' => $root['percent'] ?? 0,
            ],
            'workloads' => [
                'docker_running' => $resources['docker']['running'] ?? 0,
                'docker_total' => $resources['docker']['all'] ?? 0,
                'incus_running' => $resources['incus']['running'] ?? 0,
                'incus_total' => $resources['incus']['all'] ?? 0,
            ],
            'health' => [
                'checks_total' => $summary['total'],
                'checks_clean' => $summary['ok'],
                'checks_warning' => $summary['warning'],
                'checks_critical' => $summary['critical'],
            ],
            'updates' => [
                'pending' => $resources['updates']['planned'] ?? 0,
                'security' => $resources['updates']['security'] ?? 0,
            ],
            ...($network !== [] ? ['network' => $network] : []),
        ];
    }

    /**
     * Send the current status snapshot to the configured Hub. Never throws:
     * failures are logged and recorded, not surfaced as exceptions, because
     * reporting must never be able to affect anything else on this host.
     *
     * @return array{sent: bool, reason: string}
     */
    public function report(): array
    {
        if (! $this->configured()) {
            return ['sent' => false, 'reason' => 'not_configured'];
        }

        try {
            $response = Http::baseUrl((string) $this->baseUrl())
                ->withToken((string) $this->token())
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->post('/api/ingest', $this->buildPayload());

            if ($response->successful()) {
                Setting::put('hub.last_reported_at', now()->toIso8601String());
                Setting::put('hub.last_status', 'ok');

                return ['sent' => true, 'reason' => 'ok'];
            }

            Setting::put('hub.last_status', 'http_'.$response->status());
            Log::warning('Hub report rejected', ['status' => $response->status()]);

            return ['sent' => false, 'reason' => 'http_'.$response->status()];
        } catch (Throwable $exception) {
            Setting::put('hub.last_status', 'error');
            Log::warning('Hub report failed', ['message' => $exception->getMessage()]);

            return ['sent' => false, 'reason' => 'error'];
        }
    }

    private function token(): ?string
    {
        return Setting::get('hub.token');
    }
}
