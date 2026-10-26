<?php

namespace App\Services\Host;

use App\Exceptions\HostCommandException;
use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\User;
use Closure;
use Throwable;

class BackupService
{
    public function __construct(private readonly PrivilegedHost $host) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $jobs = BackupJob::query()
            ->withCount('runs')
            ->orderBy('name')
            ->get();

        $runs = BackupRun::query()
            ->with(['job:id,name'])
            ->latest('id')
            ->limit(40)
            ->get();

        $inventory = $this->inventory();

        return [
            'jobs' => $jobs,
            'runs' => $runs,
            'inventory' => $inventory,
            'counts' => [
                'jobs' => $jobs->count(),
                'enabled' => $jobs->where('enabled', true)->count(),
                'failing' => $jobs->where('last_status', 'failed')->count(),
                'snapshots' => $inventory['zfs_snapshot_count'] + $inventory['incus_snapshot_count'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inventory(): array
    {
        try {
            return ['available' => true, 'error' => null] + $this->host->run('backup.inventory', [], 60);
        } catch (HostCommandException $exception) {
            return [
                'available' => false,
                'error' => $exception->getMessage(),
                'datasets' => [],
                'instances' => [],
                'zfs_snapshots' => [],
                'incus_snapshots' => [],
                'zfs_snapshot_count' => 0,
                'incus_snapshot_count' => 0,
            ];
        }
    }

    public function runJob(BackupJob $job): BackupRun
    {
        $snapshot = $job->snapshotPrefix().now()->format('Ymd-His');

        return $this->perform(
            $job->kind,
            $job->target,
            'create',
            $snapshot,
            $job,
            null,
            function () use ($job, $snapshot): array {
                $this->host->run('backup.snapshot_create', [
                    'kind' => $job->kind,
                    'target' => $job->target,
                    'snapshot' => $snapshot,
                    'recursive' => $job->recursive,
                ], 240);

                $pruned = $this->host->run('backup.prune', [
                    'kind' => $job->kind,
                    'target' => $job->target,
                    'prefix' => $job->snapshotPrefix(),
                    'keep' => $job->retention,
                    'recursive' => $job->recursive,
                ], 300);

                return [
                    'message' => sprintf(
                        'Snapshot %s created; %d older snapshot(s) pruned.',
                        $snapshot,
                        count($pruned['deleted'] ?? []),
                    ),
                    'meta' => ['pruned' => $pruned['deleted'] ?? []],
                ];
            },
        );
    }

    public function createSnapshot(string $kind, string $target, bool $recursive, ?User $user = null): BackupRun
    {
        $snapshot = 'serverdeck-manual-'.now()->format('Ymd-His');

        return $this->perform(
            $kind,
            $target,
            'create',
            $snapshot,
            null,
            $user,
            function () use ($kind, $target, $snapshot, $recursive): array {
                $this->host->run('backup.snapshot_create', [
                    'kind' => $kind,
                    'target' => $target,
                    'snapshot' => $snapshot,
                    'recursive' => $recursive,
                ], 240);

                return ['message' => "Manual snapshot {$snapshot} created."];
            },
        );
    }

    public function deleteSnapshot(string $kind, string $target, string $snapshot, ?User $user = null): BackupRun
    {
        return $this->perform(
            $kind,
            $target,
            'delete',
            $snapshot,
            null,
            $user,
            function () use ($kind, $target, $snapshot): array {
                $this->host->run('backup.snapshot_delete', [
                    'kind' => $kind,
                    'target' => $target,
                    'snapshot' => $snapshot,
                ], 180);

                return ['message' => "Snapshot {$snapshot} deleted."];
            },
        );
    }

    public function restoreSnapshot(string $kind, string $target, string $snapshot, ?User $user = null): BackupRun
    {
        return $this->perform(
            $kind,
            $target,
            'restore',
            $snapshot,
            null,
            $user,
            function () use ($kind, $target, $snapshot): array {
                $this->host->run('backup.snapshot_restore', [
                    'kind' => $kind,
                    'target' => $target,
                    'snapshot' => $snapshot,
                ], 300);

                return ['message' => "Snapshot {$snapshot} restored."];
            },
        );
    }

    public function setEnabled(BackupJob $job, bool $enabled): BackupJob
    {
        $job->update([
            'enabled' => $enabled,
            'next_run_at' => $enabled ? $job->nextRunAfter() : null,
        ]);

        return $job->refresh();
    }

    public function reschedule(BackupJob $job): BackupJob
    {
        $job->update([
            'next_run_at' => $job->enabled ? $job->nextRunAfter() : null,
        ]);

        return $job->refresh();
    }

    /**
     * @param  Closure(): array{message?: string, meta?: array<string, mixed>}  $callback
     */
    private function perform(
        string $kind,
        string $target,
        string $action,
        ?string $snapshot,
        ?BackupJob $job,
        ?User $user,
        Closure $callback,
    ): BackupRun {
        $startedAt = now();
        $startedMicrotime = microtime(true);

        $run = BackupRun::create([
            'backup_job_id' => $job?->id,
            'user_id' => $user?->id,
            'kind' => $kind,
            'target' => $target,
            'action' => $action,
            'snapshot' => $snapshot,
            'status' => 'running',
            'started_at' => $startedAt,
        ]);

        try {
            $result = $callback();
            $run->update([
                'status' => 'success',
                'message' => $result['message'] ?? null,
                'meta' => $result['meta'] ?? null,
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $startedMicrotime) * 1000),
            ]);

            $job?->update([
                'last_run_at' => now(),
                'last_status' => 'success',
                'last_error' => null,
                'next_run_at' => $job->nextRunAfter(),
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'message' => $exception->getMessage(),
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $startedMicrotime) * 1000),
            ]);

            $job?->update([
                'last_run_at' => now(),
                'last_status' => 'failed',
                'last_error' => $exception->getMessage(),
                'next_run_at' => $job->nextRunAfter(),
            ]);

            throw $exception;
        }

        return $run->refresh();
    }
}
