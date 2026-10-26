<?php

namespace App\Console\Commands;

use App\Services\AlertService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('alerts:run')]
#[Description('Evaluate host health checks and dispatch alert notifications on state transitions')]
class RunAlertChecks extends Command
{
    public function handle(AlertService $alerts): int
    {
        try {
            $counts = $alerts->evaluate();
        } catch (Throwable $exception) {
            $this->error('Alert evaluation failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Evaluated %d checks: %d triggered, %d recovered, %d skipped, %d deliveries, %d failed.',
            $counts['evaluated'],
            $counts['triggered'],
            $counts['recovered'],
            $counts['skipped'],
            $counts['deliveries'],
            $counts['failed'],
        ));

        return self::SUCCESS;
    }
}
