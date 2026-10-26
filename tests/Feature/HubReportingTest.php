<?php

namespace Tests\Feature;

use App\Console\Commands\ReportToHub;
use App\Models\Setting;
use App\Services\Host\HostHealthService;
use App\Services\Host\HostMetricsService;
use App\Services\Host\NetworkConnectors\NetworkConnector;
use App\Services\HubReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithHost;
use Tests\TestCase;

class HubReportingTest extends TestCase
{
    use InteractsWithHost, RefreshDatabase;

    public function test_it_is_not_configured_without_a_url_and_token(): void
    {
        $this->fakeHostTelemetry();

        $hub = app(HubReportingService::class);

        $this->assertFalse($hub->configured());
        $this->assertSame(['sent' => false, 'reason' => 'not_configured'], $hub->report());
    }

    public function test_the_payload_reflects_real_host_telemetry_and_nothing_else(): void
    {
        $this->fakeHostTelemetry();

        $hub = app(HubReportingService::class);
        $payload = $hub->buildPayload();

        $this->assertSame('pve', $payload['host']['hostname']);
        $this->assertSame('Ubuntu 26.04.1 LTS', $payload['host']['os']);
        $this->assertSame('7.0.0-38-generic', $payload['host']['kernel']);
        $this->assertSame(86400, $payload['host']['uptime_seconds']);

        $this->assertSame(12.5, $payload['metrics']['cpu_percent']);
        $this->assertSame(40.0, $payload['metrics']['memory_percent']);
        $this->assertSame(40.0, $payload['metrics']['root_disk_percent']);

        $this->assertSame(3, $payload['workloads']['docker_running']);
        $this->assertSame(4, $payload['workloads']['docker_total']);
        $this->assertSame(3, $payload['workloads']['incus_running']);
        $this->assertSame(3, $payload['workloads']['incus_total']);

        $this->assertSame(2, $payload['updates']['pending']);
        $this->assertSame(1, $payload['updates']['security']);

        // No credentials, console content, or anything beyond the fixed shape.
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('console', $payload);
    }

    public function test_it_posts_the_payload_with_a_bearer_token_when_configured(): void
    {
        $this->fakeHostTelemetry();

        Setting::put('hub.url', 'https://hub.example.com/');
        Setting::put('hub.token', 'enrollment-token', true);

        Http::fake([
            'https://hub.example.com/api/ingest' => Http::response(['ok' => true], 200),
        ]);

        $hub = app(HubReportingService::class);
        $result = $hub->report();

        $this->assertTrue($result['sent']);
        $this->assertSame('ok', $result['reason']);
        $this->assertSame('ok', Setting::get('hub.last_status'));
        $this->assertNotNull(Setting::get('hub.last_reported_at'));

        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://hub.example.com/api/ingest'
            && $request->hasHeader('Authorization', 'Bearer enrollment-token')
            && $request['host']['hostname'] === 'pve');
    }

    public function test_it_records_a_failed_report_without_throwing(): void
    {
        $this->fakeHostTelemetry();

        Setting::put('hub.url', 'https://hub.example.com');
        Setting::put('hub.token', 'enrollment-token', true);

        Http::fake([
            'https://hub.example.com/api/ingest' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $hub = app(HubReportingService::class);
        $result = $hub->report();

        $this->assertFalse($result['sent']);
        $this->assertSame('http_401', $result['reason']);
        $this->assertSame('http_401', Setting::get('hub.last_status'));
    }

    public function test_the_scheduled_command_is_a_no_op_when_not_configured(): void
    {
        $this->fakeHostTelemetry();

        $this->artisan(ReportToHub::class)
            ->expectsOutputToContain('Hub reporting is not configured')
            ->assertSuccessful();
    }

    public function test_the_scheduled_command_reports_when_configured(): void
    {
        $this->fakeHostTelemetry();

        Setting::put('hub.url', 'https://hub.example.com');
        Setting::put('hub.token', 'enrollment-token', true);

        Http::fake([
            'https://hub.example.com/api/ingest' => Http::response(['ok' => true], 200),
        ]);

        $this->artisan(ReportToHub::class)
            ->expectsOutputToContain('Reported status to the Hub')
            ->assertSuccessful();
    }

    public function test_network_connector_detections_are_included_when_present(): void
    {
        $this->fakeHostTelemetry();

        $connector = new class implements NetworkConnector
        {
            public function key(): string
            {
                return 'tailscale';
            }

            public function detect(): ?array
            {
                return ['hostname' => 'web-03.tailnet-name.ts.net.', 'ip' => '100.64.12.9'];
            }
        };

        $hub = new HubReportingService(
            app(HostMetricsService::class),
            app(HostHealthService::class),
            [$connector],
        );

        $payload = $hub->buildPayload();

        $this->assertSame([
            'tailscale' => ['hostname' => 'web-03.tailnet-name.ts.net.', 'ip' => '100.64.12.9'],
        ], $payload['network']);
    }

    public function test_the_network_key_is_omitted_when_no_connector_detects_anything(): void
    {
        $this->fakeHostTelemetry();

        $hub = app(HubReportingService::class);
        $payload = $hub->buildPayload();

        $this->assertArrayNotHasKey('network', $payload);
    }
}
