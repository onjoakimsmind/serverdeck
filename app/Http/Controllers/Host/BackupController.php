<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\BackupJob;
use App\Services\AuditLogger;
use App\Services\Host\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BackupController extends Controller
{
    public function index(BackupService $backups): Response
    {
        return Inertia::render('Backups/Index', [
            'backups' => $backups->overview(),
        ]);
    }

    public function store(Request $request, BackupService $backups, AuditLogger $audit): RedirectResponse
    {
        $validated = $this->validateJob($request);
        $this->assertTargetExists($backups, $validated['kind'], $validated['target']);

        $job = BackupJob::create([
            'name' => $validated['name'],
            'kind' => $validated['kind'],
            'target' => $validated['target'],
            'frequency' => $validated['frequency'],
            'hour' => $validated['hour'],
            'minute' => $validated['minute'],
            'weekday' => $validated['frequency'] === 'weekly' ? ($validated['weekday'] ?? 1) : null,
            'recursive' => $validated['kind'] === 'zfs' && (bool) ($validated['recursive'] ?? false),
            'retention' => $validated['retention'],
            'enabled' => (bool) ($validated['enabled'] ?? true),
            'next_run_at' => null,
        ]);

        $backups->reschedule($job);

        $audit->log(
            'backup.job.store',
            "{$request->user()->name} created backup job {$job->name}",
            $job,
            [
                'kind' => $job->kind,
                'target' => $job->target,
                'frequency' => $job->frequency,
                'retention' => $job->retention,
                'recursive' => $job->recursive,
                'next_run_at' => $job->next_run_at?->toIso8601String(),
            ],
        );

        return back()->with('success', "Backup job {$job->name} was created.");
    }

    public function update(Request $request, BackupJob $backupJob, BackupService $backups, AuditLogger $audit): RedirectResponse
    {
        $validated = $this->validateJob($request, $backupJob);
        $this->assertTargetExists($backups, $validated['kind'], $validated['target']);

        $backupJob->update([
            'name' => $validated['name'],
            'kind' => $validated['kind'],
            'target' => $validated['target'],
            'frequency' => $validated['frequency'],
            'hour' => $validated['hour'],
            'minute' => $validated['minute'],
            'weekday' => $validated['frequency'] === 'weekly' ? ($validated['weekday'] ?? 1) : null,
            'recursive' => $validated['kind'] === 'zfs' && (bool) ($validated['recursive'] ?? false),
            'retention' => $validated['retention'],
            'enabled' => (bool) ($validated['enabled'] ?? true),
        ]);

        $backups->reschedule($backupJob);

        $audit->log(
            'backup.job.update',
            "{$request->user()->name} updated backup job {$backupJob->name}",
            $backupJob,
            ['kind' => $backupJob->kind, 'target' => $backupJob->target, 'frequency' => $backupJob->frequency],
        );

        return back()->with('success', "Backup job {$backupJob->name} was updated.");
    }

    public function destroy(Request $request, BackupJob $backupJob, AuditLogger $audit): RedirectResponse
    {
        $name = $backupJob->name;
        $backupJob->delete();

        $audit->log(
            'backup.job.delete',
            "{$request->user()->name} deleted backup job {$name}",
            null,
            ['name' => $name],
        );

        return back()->with('success', "Backup job {$name} was deleted. Existing snapshots were kept.");
    }

    public function toggle(Request $request, BackupJob $backupJob, BackupService $backups, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $backups->setEnabled($backupJob, (bool) $validated['enabled']);

        $audit->log(
            'backup.job.toggle',
            sprintf(
                '%s %s backup job %s',
                $request->user()->name,
                $validated['enabled'] ? 'enabled' : 'paused',
                $backupJob->name,
            ),
            $backupJob,
            ['enabled' => (bool) $validated['enabled']],
        );

        return back()->with(
            'success',
            $validated['enabled'] ? "Backup job {$backupJob->name} was enabled." : "Backup job {$backupJob->name} was paused.",
        );
    }

    public function run(Request $request, BackupJob $backupJob, BackupService $backups, AuditLogger $audit): RedirectResponse
    {
        try {
            $run = $backups->runJob($backupJob);
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->log(
            'backup.job.run',
            "{$request->user()->name} ran backup job {$backupJob->name}",
            $backupJob,
            ['snapshot' => $run->snapshot, 'message' => $run->message],
        );

        return back()->with('success', $run->message ?? "Backup job {$backupJob->name} completed.");
    }

    public function snapshot(Request $request, BackupService $backups, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(['zfs', 'incus'])],
            'target' => ['required', 'string', 'max:255'],
            'recursive' => ['boolean'],
        ]);

        $this->assertTargetExists($backups, $validated['kind'], $validated['target']);

        try {
            $run = $backups->createSnapshot(
                $validated['kind'],
                $validated['target'],
                $validated['kind'] === 'zfs' && (bool) ($validated['recursive'] ?? false),
                $request->user(),
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->log(
            'backup.snapshot.create',
            "{$request->user()->name} created {$validated['kind']} snapshot {$run->snapshot} on {$validated['target']}",
            null,
            ['kind' => $validated['kind'], 'target' => $validated['target'], 'snapshot' => $run->snapshot],
        );

        return back()->with('success', $run->message ?? 'Snapshot created.');
    }

    public function deleteSnapshot(Request $request, BackupService $backups, AuditLogger $audit): RedirectResponse
    {
        $validated = $this->validateSnapshot($request);

        try {
            $run = $backups->deleteSnapshot(
                $validated['kind'],
                $validated['target'],
                $validated['snapshot'],
                $request->user(),
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->log(
            'backup.snapshot.delete',
            "{$request->user()->name} deleted {$validated['kind']} snapshot {$validated['snapshot']} on {$validated['target']}",
            null,
            $validated,
        );

        return back()->with('success', $run->message ?? 'Snapshot deleted.');
    }

    public function restoreSnapshot(Request $request, BackupService $backups, AuditLogger $audit): RedirectResponse
    {
        $validated = $this->validateSnapshot($request);

        try {
            $run = $backups->restoreSnapshot(
                $validated['kind'],
                $validated['target'],
                $validated['snapshot'],
                $request->user(),
            );
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->log(
            'backup.snapshot.restore',
            "{$request->user()->name} restored {$validated['kind']} snapshot {$validated['snapshot']} on {$validated['target']}",
            null,
            $validated,
        );

        return back()->with('success', $run->message ?? 'Snapshot restored.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateJob(Request $request, ?BackupJob $job = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('backup_jobs', 'name')->ignore($job?->id),
            ],
            'kind' => ['required', Rule::in(['zfs', 'incus'])],
            'target' => ['required', 'string', 'max:255'],
            'frequency' => ['required', Rule::in(['hourly', 'daily', 'weekly', 'monthly'])],
            'hour' => ['required', 'integer', 'min:0', 'max:23'],
            'minute' => ['required', 'integer', 'min:0', 'max:59'],
            'weekday' => ['nullable', 'integer', 'min:1', 'max:7'],
            'recursive' => ['boolean'],
            'retention' => ['required', 'integer', 'min:1', 'max:500'],
            'enabled' => ['boolean'],
        ]);
    }

    /**
     * @return array{kind: string, target: string, snapshot: string}
     */
    private function validateSnapshot(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', Rule::in(['zfs', 'incus'])],
            'target' => ['required', 'string', 'max:255'],
            'snapshot' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
        ]);
    }

    private function assertTargetExists(BackupService $backups, string $kind, string $target): void
    {
        $inventory = $backups->inventory();

        if (! ($inventory['available'] ?? false)) {
            throw ValidationException::withMessages([
                'target' => $inventory['error'] ?? 'The snapshot inventory is unavailable.',
            ]);
        }

        $names = $kind === 'zfs'
            ? collect($inventory['datasets'] ?? [])->pluck('name')->all()
            : collect($inventory['instances'] ?? [])->pluck('name')->all();

        if (! in_array($target, $names, true)) {
            throw ValidationException::withMessages([
                'target' => $kind === 'zfs'
                    ? 'Choose a ZFS dataset that exists on this host.'
                    : 'Choose an Incus instance that exists on this host.',
            ]);
        }
    }
}
