<?php

namespace App\Console\Commands;

use App\Models\BackupJob;
use App\Services\Host\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('backups:run')]
#[Description('Create scheduled ZFS and Incus snapshots, then prune older ones')]
class RunBackupJobs extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BackupService $backups): int
    {
        $jobs = BackupJob::query()
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->get();

        if ($jobs->isEmpty()) {
            $this->info('No backup jobs are due.');

            return self::SUCCESS;
        }

        foreach ($jobs as $job) {
            try {
                $run = $backups->runJob($job);
                $this->info("{$job->name}: {$run->message}");
            } catch (Throwable $exception) {
                $this->error("{$job->name}: {$exception->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
