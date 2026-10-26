<?php

namespace Database\Factories;

use App\Models\AlertChannel;
use App\Models\AlertEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertEvent>
 */
class AlertEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'alert_channel_id' => AlertChannel::factory()->state(['name' => 'Operations email']),
            'channel_name' => 'Operations email',
            'event' => 'alert',
            'check_key' => 'smart',
            'check_title' => 'SMART disk health',
            'status' => 'critical',
            'message' => 'SMART disk health is critical: 294 pending sectors on sdc',
            'delivered' => true,
            'error' => null,
        ];
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'delivered' => false,
            'error' => 'Connection timed out after 15000 milliseconds',
        ]);
    }
}
