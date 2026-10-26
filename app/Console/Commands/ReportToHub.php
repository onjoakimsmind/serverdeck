<?php

namespace App\Console\Commands;

use App\Services\HubReportingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hub:report')]
#[Description('Send a one-way status snapshot to the configured ServerDeck Hub, if any')]
class ReportToHub extends Command
{
    public function handle(HubReportingService $hub): int
    {
        if (! $hub->configured()) {
            $this->comment('Hub reporting is not configured; nothing to do.');

            return self::SUCCESS;
        }

        $result = $hub->report();

        if ($result['sent']) {
            $this->info('Reported status to the Hub.');

            return self::SUCCESS;
        }

        $this->warn("Hub report was not sent ({$result['reason']}).");

        return self::FAILURE;
    }
}
