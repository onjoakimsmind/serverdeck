<?php

namespace Database\Factories;

use App\Models\BackupJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupJob>
 */
class BackupJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Nightly storage snapshot',
            'kind' => 'zfs',
            'target' => 'storage',
            'frequency' => 'daily',
            'hour' => 3,
            'minute' => 0,
            'weekday' => null,
            'recursive' => true,
            'retention' => 7,
            'enabled' => true,
            'last_run_at' => null,
            'last_status' => null,
            'last_error' => null,
            'next_run_at' => now()->addDay(),
        ];
    }

    public function incus(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Nightly instance snapshot',
            'kind' => 'incus',
            'target' => 'hermes',
            'recursive' => false,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
            'next_run_at' => null,
        ]);
    }
}
