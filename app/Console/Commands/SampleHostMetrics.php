<?php

namespace App\Console\Commands;

use App\Services\Host\MetricHistoryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('metrics:sample')]
#[Description('Persist a host metric sample and prune history older than the retention window')]
class SampleHostMetrics extends Command
{
    public function handle(MetricHistoryService $history): int
    {
        try {
            $sample = $history->sample();
        } catch (Throwable $exception) {
            $this->error('Metrics sampling failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Recorded metrics sample at %s: CPU %.1f%% · memory %.1f%% · disk %.1f%%.',
            $sample->recorded_at->toIso8601String(),
            $sample->cpu_usage,
            $sample->memory_percent,
            $sample->disk_percent,
        ));

        return self::SUCCESS;
    }
}
