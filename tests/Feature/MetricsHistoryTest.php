<?php

namespace Tests\Feature;

use App\Models\MetricSample;
use App\Models\User;
use App\Services\Host\HostMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\InteractsWithHost;
use Tests\TestCase;

class MetricsHistoryTest extends TestCase
{
    use InteractsWithHost, RefreshDatabase;

    public function test_the_history_api_returns_bucketed_samples_and_summary(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00'));

        MetricSample::factory()->recordedAt(CarbonImmutable::parse('2026-10-26 10:01:00'))->create([
            'cpu_usage' => 10.5,
            'memory_percent' => 20.5,
            'disk_percent' => 30.5,
        ]);
        MetricSample::factory()->recordedAt(CarbonImmutable::parse('2026-10-26 10:03:00'))->create([
            'cpu_usage' => 30.5,
            'memory_percent' => 40.5,
            'disk_percent' => 50.5,
        ]);
        MetricSample::factory()->recordedAt(CarbonImmutable::parse('2026-10-26 11:00:00'))->create([
            'cpu_usage' => 50.5,
            'memory_percent' => 60.5,
            'disk_percent' => 70.5,
        ]);

        $response = $this->actingAs(User::factory()->viewer()->create())
            ->getJson('/api/metrics/history?range=24h');

        $response->assertOk()
            ->assertJsonPath('range', '24h')
            ->assertJsonPath('label', 'Last 24 hours')
            ->assertJsonPath('bucket_minutes', 5)
            ->assertJsonPath('retention_days', 30)
            ->assertJsonCount(2, 'points')
            ->assertJsonPath('points.0.cpu', 20.5)
            ->assertJsonPath('points.0.memory', 30.5)
            ->assertJsonPath('points.0.disk', 40.5)
            ->assertJsonPath('points.0.samples', 2)
            ->assertJsonPath('points.1.cpu', 50.5)
            ->assertJsonPath('points.1.samples', 1)
            ->assertJsonPath('summary.samples', 3)
            ->assertJsonPath('summary.cpu.avg', 30.5)
            ->assertJsonPath('summary.cpu.max', 50.5)
            ->assertJsonPath('summary.memory.avg', 40.5)
            ->assertJsonPath('summary.disk.max', 70.5);
    }

    public function test_the_history_api_supports_24_hour_and_seven_day_ranges(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00'));

        MetricSample::factory()->recordedAt(CarbonImmutable::parse('2026-10-24 12:00:00'))->create();
        MetricSample::factory()->recordedAt(CarbonImmutable::parse('2026-10-26 11:00:00'))->create();

        $user = User::factory()->viewer()->create();

        $this->actingAs($user)
            ->getJson('/api/metrics/history?range=24h')
            ->assertOk()
            ->assertJsonPath('range', '24h')
            ->assertJsonCount(1, 'points')
            ->assertJsonPath('summary.samples', 1);

        $this->actingAs($user)
            ->getJson('/api/metrics/history?range=7d')
            ->assertOk()
            ->assertJsonPath('range', '7d')
            ->assertJsonPath('label', 'Last 7 days')
            ->assertJsonPath('bucket_minutes', 30)
            ->assertJsonCount(2, 'points')
            ->assertJsonPath('summary.samples', 2);
    }

    public function test_the_history_api_returns_an_empty_series_without_samples(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00'));

        $this->actingAs(User::factory()->viewer()->create())
            ->getJson('/api/metrics/history?range=24h')
            ->assertOk()
            ->assertJsonCount(0, 'points')
            ->assertJsonPath('summary.samples', 0)
            ->assertJsonPath('summary.cpu.avg', 0)
            ->assertJsonPath('summary.cpu.max', 0);
    }

    public function test_the_history_api_rejects_unknown_ranges(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->getJson('/api/metrics/history?range=30d')
            ->assertStatus(422)
            ->assertJsonValidationErrors('range');
    }

    public function test_metrics_history_requires_authentication(): void
    {
        $this->get('/api/metrics/history?range=24h')->assertUnauthorized();
    }

    public function test_the_sampler_persists_a_sample_and_prunes_expired_history(): void
    {
        $this->fakeMetrics();

        $expired = MetricSample::factory()->recordedAt(now()->subDays(31))->create();
        $recent = MetricSample::factory()->recordedAt(now()->subDays(29))->create();

        $this->artisan('metrics:sample')
            ->expectsOutputToContain('Recorded metrics sample')
            ->assertSuccessful();

        $this->assertDatabaseMissing('metric_samples', ['id' => $expired->id]);
        $this->assertDatabaseHas('metric_samples', ['id' => $recent->id]);

        $sample = MetricSample::query()->latest('id')->firstOrFail();

        $this->assertSame(12.5, $sample->cpu_usage);
        $this->assertSame(40.0, $sample->memory_percent);
        $this->assertSame(0.0, $sample->swap_percent);
        $this->assertSame(40.0, $sample->disk_percent);
        $this->assertSame(0.5, $sample->load_1);
        $this->assertSame(250, $sample->processes);
        $this->assertNull($sample->temperature_celsius);
    }

    public function test_the_sampler_reports_failures_without_writing_a_sample(): void
    {
        $this->mock(HostMetricsService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('snapshot')->once()->andThrow(new RuntimeException('telemetry unavailable'));
        });

        $this->artisan('metrics:sample')
            ->expectsOutputToContain('Metrics sampling failed: telemetry unavailable')
            ->assertFailed();

        $this->assertDatabaseCount('metric_samples', 0);
    }
}
