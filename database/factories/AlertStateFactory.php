<?php

namespace Database\Factories;

use App\Models\AlertState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertState>
 */
class AlertStateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'check_key' => 'smart',
            'check_title' => 'SMART disk health',
            'last_status' => 'critical',
            'last_notified_status' => 'critical',
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now(),
            'last_notified_at' => now()->subMinutes(30),
            'resolved_at' => null,
        ];
    }

    public function warning(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_status' => 'warning',
            'last_notified_status' => 'warning',
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_status' => 'ok',
            'last_notified_status' => 'recovery',
            'first_seen_at' => null,
            'resolved_at' => now(),
        ]);
    }
}
