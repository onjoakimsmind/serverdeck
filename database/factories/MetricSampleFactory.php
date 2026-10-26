<?php

namespace Database\Factories;

use App\Models\MetricSample;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricSample>
 */
class MetricSampleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'recorded_at' => now(),
            'cpu_usage' => 12.5,
            'memory_percent' => 40.0,
            'swap_percent' => 0.0,
            'disk_percent' => 40.0,
            'load_1' => 0.5,
            'load_5' => 0.4,
            'load_15' => 0.3,
            'network_rx_rate' => 1024.0,
            'network_tx_rate' => 512.0,
            'processes' => 250,
            'temperature_celsius' => null,
        ];
    }

    public function recordedAt(\DateTimeInterface $recordedAt): static
    {
        return $this->state(fn (array $attributes) => [
            'recorded_at' => $recordedAt,
        ]);
    }
}
