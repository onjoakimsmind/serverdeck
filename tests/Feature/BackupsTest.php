<?php

namespace Tests\Feature;

use App\Models\BackupJob;
use App\Models\BackupRun;
use App\Models\User;
use App\Services\Host\BackupService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithHost;
use Tests\TestCase;

class BackupsTest extends TestCase
{
    use InteractsWithHost, RefreshDatabase;

    public function test_the_backups_page_lists_schedules_and_inventory(): void
    {
        $job = BackupJob::factory()->create([
            'name' => 'Nightly storage',
            'target' => 'storage',
            'next_run_at' => now()->addDay(),
        ]);

        $this->mock(BackupService::class, function (MockInterface $mock) use ($job): void {
            $mock->shouldReceive('overview')->once()->andReturn([
                'jobs' => [$job],
                'runs' => [],
                'inventory' => $this->backupInventory(),
                'counts' => ['jobs' => 1, 'enabled' => 1, 'failing' => 0, 'snapshots' => 1],
            ]);
        });

        $this->actingAs(User::factory()->viewer()->create())
            ->get('/backups')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Backups/Index')
                ->where('backups.jobs.0.name', 'Nightly storage')
                ->where('backups.inventory.datasets.0.name', 'storage')
                ->where('backups.counts.snapshots', 1));
    }

    public function test_operators_can_create_a_backup_schedule(): void
    {
        $this->mock(BackupService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('inventory')->once()->andReturn($this->backupInventory());
            $mock->shouldReceive('reschedule')->once()->andReturnUsing(fn (BackupJob $job) => $job);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/backups/jobs', [
                'name' => 'Nightly storage',
                'kind' => 'zfs',
                'target' => 'storage',
                'frequency' => 'daily',
                'hour' => 3,
                'minute' => 15,
                'weekday' => 1,
                'recursive' => true,
                'retention' => 14,
                'enabled' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $job = BackupJob::query()->where('name', 'Nightly storage')->first();

        $this->assertNotNull($job);
        $this->assertSame('zfs', $job->kind);
        $this->assertSame('storage', $job->target);
        $this->assertSame(14, $job->retention);
        $this->assertTrue($job->recursive);
        $this->assertNull($job->weekday);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.job.store']);
    }

    public function test_schedule_targets_must_exist_on_the_host(): void
    {
        $this->mock(BackupService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('inventory')->once()->andReturn($this->backupInventory());
            $mock->shouldNotReceive('reschedule');
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/backups/jobs', [
                'name' => 'Missing target',
                'kind' => 'zfs',
                'target' => 'missing/dataset',
                'frequency' => 'daily',
                'hour' => 3,
                'minute' => 0,
                'retention' => 7,
                'enabled' => true,
            ])
            ->assertSessionHasErrors('target');

        $this->assertDatabaseMissing('backup_jobs', ['name' => 'Missing target']);
    }

    public function test_operators_can_update_a_backup_schedule(): void
    {
        $job = BackupJob::factory()->create(['name' => 'Old schedule']);

        $this->mock(BackupService::class, function (MockInterface $mock) use ($job): void {
            $mock->shouldReceive('inventory')->once()->andReturn($this->backupInventory());
            $mock->shouldReceive('reschedule')->once()->with(Mockery::on(fn (BackupJob $argument): bool => $argument->is($job)))->andReturn($job);
        });

        $this->actingAs(User::factory()->admin()->create())
            ->put("/backups/jobs/{$job->id}", [
                'name' => 'Weekly storage',
                'kind' => 'zfs',
                'target' => 'storage',
                'frequency' => 'weekly',
                'hour' => 4,
                'minute' => 30,
                'weekday' => 5,
                'recursive' => false,
                'retention' => 8,
                'enabled' => false,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $job->refresh();

        $this->assertSame('Weekly storage', $job->name);
        $this->assertSame('weekly', $job->frequency);
        $this->assertSame(5, $job->weekday);
        $this->assertFalse($job->enabled);
        $this->assertFalse($job->recursive);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.job.update']);
    }

    public function test_a_schedule_can_be_paused_and_deleted(): void
    {
        $job = BackupJob::factory()->create();

        $this->mock(BackupService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('setEnabled')
                ->once()
                ->with(Mockery::type(BackupJob::class), false)
                ->andReturnUsing(function (BackupJob $job): BackupJob {
                    $job->update(['enabled' => false, 'next_run_at' => null]);

                    return $job;
                });
        });

        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->post("/backups/jobs/{$job->id}/toggle", ['enabled' => false])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($job->refresh()->enabled);

        $this->actingAs($operator)
            ->delete("/backups/jobs/{$job->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('backup_jobs', ['id' => $job->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.job.delete']);
    }

    public function test_operators_can_run_a_schedule_on_demand(): void
    {
        $job = BackupJob::factory()->create(['name' => 'Run now']);

        $this->mock(BackupService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('runJob')
                ->once()
                ->andReturn(new BackupRun([
                    'snapshot' => 'serverdeck-job1-20261026-030000',
                    'message' => 'Snapshot created; 0 older snapshot(s) pruned.',
                ]));
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post("/backups/jobs/{$job->id}/run")
            ->assertRedirect()
            ->assertSessionHas('success', 'Snapshot created; 0 older snapshot(s) pruned.');

        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.job.run']);
    }

    public function test_operators_can_create_manual_snapshots_and_restore_or_delete_them(): void
    {
        $this->mock(BackupService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('inventory')->once()->andReturn($this->backupInventory());
            $mock->shouldReceive('createSnapshot')
                ->once()
                ->with('zfs', 'storage', true, Mockery::type(User::class))
                ->andReturn(new BackupRun(['snapshot' => 'serverdeck-manual-20261026-080000', 'message' => 'Manual snapshot created.']));
            $mock->shouldReceive('deleteSnapshot')
                ->once()
                ->andReturn(new BackupRun(['snapshot' => 'serverdeck-manual-20261026-080000', 'message' => 'Snapshot deleted.']));
            $mock->shouldReceive('restoreSnapshot')
                ->once()
                ->andReturn(new BackupRun(['snapshot' => 'serverdeck-manual-20261026-080000', 'message' => 'Snapshot restored.']));
        });

        $operator = User::factory()->operator()->create();

        $this->actingAs($operator)
            ->post('/backups/snapshots', [
                'kind' => 'zfs',
                'target' => 'storage',
                'recursive' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $payload = [
            'kind' => 'zfs',
            'target' => 'storage',
            'snapshot' => 'serverdeck-manual-20261026-080000',
        ];

        $this->actingAs($operator)->post('/backups/snapshots/restore', $payload)->assertRedirect();
        $this->actingAs($operator)->post('/backups/snapshots/delete', $payload)->assertRedirect();

        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.snapshot.create']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.snapshot.restore']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.snapshot.delete']);
    }

    public function test_viewers_cannot_change_backup_state(): void
    {
        $job = BackupJob::factory()->create();
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer)->post('/backups/jobs', [])->assertForbidden();
        $this->actingAs($viewer)->post("/backups/jobs/{$job->id}/run")->assertForbidden();
        $this->actingAs($viewer)->delete("/backups/jobs/{$job->id}")->assertForbidden();

        $this->assertDatabaseHas('backup_jobs', ['id' => $job->id]);
    }

    public function test_next_run_times_follow_the_selected_cadence(): void
    {
        $daily = new BackupJob(['frequency' => 'daily', 'hour' => 3, 'minute' => 30]);

        $this->assertSame(
            '2026-10-26 03:30:00',
            $daily->nextRunAfter(CarbonImmutable::parse('2026-10-26 02:15:00'))->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-10-27 03:30:00',
            $daily->nextRunAfter(CarbonImmutable::parse('2026-10-26 04:00:00'))->format('Y-m-d H:i:s'),
        );

        $weekly = new BackupJob(['frequency' => 'weekly', 'hour' => 1, 'minute' => 0, 'weekday' => 5]);

        $this->assertSame(
            '2026-10-30 01:00:00',
            $weekly->nextRunAfter(CarbonImmutable::parse('2026-10-26 09:00:00'))->format('Y-m-d H:i:s'),
        );

        $hourly = new BackupJob(['frequency' => 'hourly', 'hour' => 0, 'minute' => 45]);

        $this->assertSame(
            '2026-10-26 10:45:00',
            $hourly->nextRunAfter(CarbonImmutable::parse('2026-10-26 10:10:00'))->format('Y-m-d H:i:s'),
        );
    }

    public function test_the_scheduler_command_runs_only_due_jobs(): void
    {
        $due = BackupJob::factory()->create([
            'name' => 'Due job',
            'enabled' => true,
            'next_run_at' => now()->subMinute(),
        ]);
        $paused = BackupJob::factory()->disabled()->create([
            'name' => 'Paused job',
            'next_run_at' => now()->subMinute(),
        ]);

        $this->mock(BackupService::class, function (MockInterface $mock) use ($due): void {
            $mock->shouldReceive('runJob')
                ->once()
                ->with(Mockery::on(fn (BackupJob $job): bool => $job->is($due)))
                ->andReturn(new BackupRun(['message' => 'Snapshot created.']));
        });

        $this->artisan('backups:run')
            ->expectsOutput('Due job: Snapshot created.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('backup_jobs', ['id' => $paused->id]);
    }

    public function test_audit_history_survives_schedule_deletion(): void
    {
        $job = BackupJob::factory()->create(['name' => 'Disposable']);
        $run = BackupRun::factory()->create(['backup_job_id' => $job->id]);

        $job->delete();

        $this->assertDatabaseHas('backup_runs', ['id' => $run->id, 'backup_job_id' => null]);
    }
}
