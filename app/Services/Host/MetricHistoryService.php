<?php

namespace App\Services\Host;

use App\Models\MetricSample;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class MetricHistoryService
{
    public const RETENTION_DAYS = 30;

    /**
     * @var array<string, array{hours: int, bucket_seconds: int, label: string}>
     */
    public const RANGES = [
        '24h' => ['hours' => 24, 'bucket_seconds' => 300, 'label' => 'Last 24 hours'],
        '7d' => ['hours' => 168, 'bucket_seconds' => 1800, 'label' => 'Last 7 days'],
    ];

    public function __construct(private readonly HostMetricsService $metrics) {}

    public function sample(): MetricSample
    {
        $snapshot = $this->metrics->snapshot();

        $sample = MetricSample::create([
            'recorded_at' => now(),
            'cpu_usage' => $snapshot['cpu']['usage'] ?? 0,
            'memory_percent' => $snapshot['memory']['percent'] ?? 0,
            'swap_percent' => $snapshot['memory']['swap_percent'] ?? 0,
            'disk_percent' => $snapshot['disks']['root']['percent'] ?? 0,
            'load_1' => $snapshot['cpu']['load_1'] ?? 0,
            'load_5' => $snapshot['cpu']['load_5'] ?? 0,
            'load_15' => $snapshot['cpu']['load_15'] ?? 0,
            'network_rx_rate' => $snapshot['network']['rx_rate'] ?? 0,
            'network_tx_rate' => $snapshot['network']['tx_rate'] ?? 0,
            'processes' => $snapshot['processes'] ?? 0,
            'temperature_celsius' => $snapshot['temperature']['celsius'] ?? null,
        ]);

        $this->prune();

        return $sample;
    }

    public function prune(): int
    {
        return MetricSample::query()
            ->where('recorded_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function history(string $range = '24h'): array
    {
        $config = self::RANGES[$range] ?? self::RANGES['24h'];
        $to = CarbonImmutable::now();
        $from = $to->subHours($config['hours']);

        $samples = MetricSample::query()
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->get();

        return [
            'range' => $range,
            'label' => $config['label'],
            'bucket_seconds' => $config['bucket_seconds'],
            'bucket_minutes' => intdiv($config['bucket_seconds'], 60),
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'retention_days' => self::RETENTION_DAYS,
            'points' => $this->points($samples, $config['bucket_seconds']),
            'summary' => $this->summary($samples),
        ];
    }

    /**
     * @param  Collection<int, MetricSample>  $samples
     * @return array<int, array<string, mixed>>
     */
    private function points(Collection $samples, int $bucketSeconds): array
    {
        return $samples
            ->groupBy(fn (MetricSample $sample): int => intdiv($sample->recorded_at->getTimestamp(), $bucketSeconds) * $bucketSeconds)
            ->map(function (Collection $rows, int|string $bucketStart): array {
                $temperatures = $rows
                    ->pluck('temperature_celsius')
                    ->filter(fn ($temperature) => $temperature !== null);

                return [
                    'at' => CarbonImmutable::createFromTimestamp((int) $bucketStart)->toIso8601String(),
                    'cpu' => round((float) $rows->avg('cpu_usage'), 1),
                    'memory' => round((float) $rows->avg('memory_percent'), 1),
                    'swap' => round((float) $rows->avg('swap_percent'), 1),
                    'disk' => round((float) $rows->avg('disk_percent'), 1),
                    'load' => round((float) $rows->avg('load_1'), 2),
                    'rx_rate' => round((float) $rows->avg('network_rx_rate'), 1),
                    'tx_rate' => round((float) $rows->avg('network_tx_rate'), 1),
                    'processes' => (int) round((float) $rows->avg('processes')),
                    'temperature' => $temperatures->isEmpty() ? null : round((float) $temperatures->avg(), 1),
                    'samples' => $rows->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, MetricSample>  $samples
     * @return array<string, mixed>
     */
    private function summary(Collection $samples): array
    {
        if ($samples->isEmpty()) {
            return [
                'samples' => 0,
                'cpu' => ['avg' => 0.0, 'max' => 0.0],
                'memory' => ['avg' => 0.0, 'max' => 0.0],
                'disk' => ['avg' => 0.0, 'max' => 0.0],
            ];
        }

        return [
            'samples' => $samples->count(),
            'cpu' => [
                'avg' => round((float) $samples->avg('cpu_usage'), 1),
                'max' => round((float) $samples->max('cpu_usage'), 1),
            ],
            'memory' => [
                'avg' => round((float) $samples->avg('memory_percent'), 1),
                'max' => round((float) $samples->max('memory_percent'), 1),
            ],
            'disk' => [
                'avg' => round((float) $samples->avg('disk_percent'), 1),
                'max' => round((float) $samples->max('disk_percent'), 1),
            ],
        ];
    }
}
