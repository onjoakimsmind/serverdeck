<?php

namespace Database\Factories;

use App\Models\BackupJob;
use App\Models\BackupRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupRun>
 */
class BackupRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'backup_job_id' => BackupJob::factory(),
            'user_id' => null,
            'kind' => 'zfs',
            'target' => 'storage',
            'action' => 'create',
            'snapshot' => 'serverdeck-job1-20261026-030000',
            'status' => 'success',
            'message' => null,
            'meta' => null,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'duration_ms' => 1200,
        ];
    }
}
