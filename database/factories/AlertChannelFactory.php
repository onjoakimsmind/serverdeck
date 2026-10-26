<?php

namespace Database\Factories;

use App\Models\AlertChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertChannel>
 */
class AlertChannelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Operations email',
            'type' => AlertChannel::TYPE_EMAIL,
            'destination' => 'ops@example.com',
            'enabled' => true,
            'notify_warning' => true,
            'notify_critical' => true,
            'notify_recovery' => true,
            'last_sent_at' => null,
            'last_error' => null,
        ];
    }

    public function webhook(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Operations webhook',
            'type' => AlertChannel::TYPE_WEBHOOK,
            'destination' => 'https://hooks.example.com/serverdeck',
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }

    public function criticalOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'notify_warning' => false,
            'notify_recovery' => false,
        ]);
    }
}
