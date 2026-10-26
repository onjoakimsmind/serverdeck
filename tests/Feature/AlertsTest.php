<?php

namespace Tests\Feature;

use App\Mail\AlertTriggered;
use App\Models\AlertChannel;
use App\Models\AlertEvent;
use App\Models\AlertState;
use App\Models\User;
use App\Services\Host\HostHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithHost;
use Tests\TestCase;

class AlertsTest extends TestCase
{
    use InteractsWithHost, RefreshDatabase;

    public function test_the_alerts_page_lists_channels_states_and_deliveries(): void
    {
        $this->fakeHostTelemetry();

        $channel = AlertChannel::factory()->create(['name' => 'Operations email']);
        $state = AlertState::factory()->create([
            'check_key' => 'smart',
            'check_title' => 'SMART disk health',
            'last_status' => 'critical',
            'last_notified_status' => 'critical',
        ]);
        AlertEvent::factory()->create([
            'alert_channel_id' => $channel->id,
            'channel_name' => $channel->name,
            'check_key' => 'smart',
            'check_title' => 'SMART disk health',
            'status' => 'critical',
        ]);

        $this->actingAs(User::factory()->viewer()->create())
            ->get('/alerts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Alerts/Index')
                ->where('alerts.channels.0.name', 'Operations email')
                ->where('alerts.channels.0.destination', null)
                ->where('alerts.channels.0.destination_hint', 'o***@example.com')
                ->where('alerts.channels.0.enabled', true)
                ->where('alerts.alerts.0.check_key', $state->check_key)
                ->where('alerts.alerts.0.status', 'critical')
                ->where('alerts.events.0.channel_name', 'Operations email')
                ->where('alerts.events.0.event', 'alert')
                ->where('alerts.events.0.delivered', true)
                ->where('alerts.counts.channels', 1)
                ->where('alerts.counts.enabled', 1));
    }

    public function test_admins_see_channel_destinations(): void
    {
        $this->fakeHostTelemetry();

        AlertChannel::factory()->create([
            'name' => 'Operations email',
            'destination' => 'ops@example.com, oncall@example.com',
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->get('/alerts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Alerts/Index')
                ->where('alerts.channels.0.destination', 'ops@example.com, oncall@example.com')
                ->where('alerts.channels.0.destination_hint', '2 recipients'));
    }

    public function test_admins_can_create_update_and_delete_alert_channels(): void
    {
        $this->fakeHostTelemetry();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/alerts/channels', [
                'name' => 'Operations email',
                'type' => 'email',
                'destination' => 'ops@example.com, oncall@example.com',
                'enabled' => true,
                'notify_warning' => true,
                'notify_critical' => true,
                'notify_recovery' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $channel = AlertChannel::query()->firstOrFail();

        $this->assertSame('ops@example.com, oncall@example.com', $channel->destination);
        $this->assertNotSame(
            'ops@example.com, oncall@example.com',
            DB::table('alert_channels')->where('id', $channel->id)->value('destination'),
        );
        $this->assertDatabaseHas('audit_logs', ['action' => 'alert.channel.store']);

        $this->actingAs($admin)
            ->put("/alerts/channels/{$channel->id}", [
                'name' => 'On-call webhook',
                'type' => 'webhook',
                'destination' => 'https://hooks.example.com/serverdeck',
                'enabled' => true,
                'notify_warning' => false,
                'notify_critical' => true,
                'notify_recovery' => false,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $channel->refresh();

        $this->assertSame('On-call webhook', $channel->name);
        $this->assertSame(AlertChannel::TYPE_WEBHOOK, $channel->type);
        $this->assertSame('https://hooks.example.com/serverdeck', $channel->destination);
        $this->assertFalse($channel->notify_warning);
        $this->assertTrue($channel->notify_critical);
        $this->assertFalse($channel->notify_recovery);
        $this->assertDatabaseHas('audit_logs', ['action' => 'alert.channel.update']);

        $this->actingAs($admin)
            ->delete("/alerts/channels/{$channel->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('alert_channels', ['id' => $channel->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'alert.channel.destroy']);
    }

    public function test_alert_channels_validate_recipients_webhooks_and_events(): void
    {
        $this->fakeHostTelemetry();

        $admin = User::factory()->admin()->create();
        $payload = [
            'name' => 'Operations email',
            'type' => 'email',
            'destination' => 'not-an-email',
            'enabled' => true,
            'notify_warning' => true,
            'notify_critical' => true,
            'notify_recovery' => true,
        ];

        $this->actingAs($admin)
            ->post('/alerts/channels', $payload)
            ->assertSessionHasErrors('destination');

        $this->actingAs($admin)
            ->post('/alerts/channels', [
                ...$payload,
                'type' => 'webhook',
                'destination' => 'ftp://hooks.example.com/serverdeck',
            ])
            ->assertSessionHasErrors('destination');

        $this->actingAs($admin)
            ->post('/alerts/channels', [
                ...$payload,
                'destination' => 'ops@example.com',
                'notify_warning' => false,
                'notify_critical' => false,
                'notify_recovery' => false,
            ])
            ->assertSessionHasErrors('notify_warning');

        $this->assertDatabaseCount('alert_channels', 0);
    }

    public function test_viewers_and_operators_cannot_manage_alert_channels(): void
    {
        $this->fakeHostTelemetry();

        $channel = AlertChannel::factory()->create();
        $payload = [
            'name' => 'Operations email',
            'type' => 'email',
            'destination' => 'ops@example.com',
            'enabled' => true,
            'notify_warning' => true,
            'notify_critical' => true,
            'notify_recovery' => true,
        ];

        foreach ([User::factory()->viewer()->create(), User::factory()->operator()->create()] as $user) {
            $this->actingAs($user)->post('/alerts/channels', $payload)->assertForbidden();
            $this->actingAs($user)->post("/alerts/channels/{$channel->id}/test")->assertForbidden();
            $this->actingAs($user)->post('/alerts/run')->assertForbidden();
        }

        $this->assertDatabaseCount('alert_channels', 1);
    }

    public function test_email_test_delivery_is_recorded(): void
    {
        $this->fakeHostTelemetry();
        Mail::fake();

        $channel = AlertChannel::factory()->create(['name' => 'Operations email']);

        $this->actingAs(User::factory()->admin()->create())
            ->post("/alerts/channels/{$channel->id}/test")
            ->assertRedirect()
            ->assertSessionHas('success', 'Test notification sent through “Operations email”.');

        Mail::assertSent(AlertTriggered::class, fn (AlertTriggered $mail) => $mail->hasTo('ops@example.com')
            && $mail->payload['event'] === 'test'
            && $mail->payload['status'] === 'test');

        $channel->refresh();

        $this->assertNotNull($channel->last_sent_at);
        $this->assertNull($channel->last_error);
        $this->assertDatabaseHas('alert_events', [
            'alert_channel_id' => $channel->id,
            'event' => 'test',
            'delivered' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'alert.channel.test']);
    }

    public function test_webhook_test_delivery_posts_json(): void
    {
        $this->fakeHostTelemetry();
        Http::fake([
            'https://hooks.example.com/serverdeck' => Http::response(['ok' => true], 200),
        ]);

        $channel = AlertChannel::factory()->webhook()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post("/alerts/channels/{$channel->id}/test")
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://hooks.example.com/serverdeck'
            && $request['event'] === 'test'
            && $request['title'] === 'Test notification'
            && $request->hasHeader('X-ServerDeck-Event', 'test'));

        $this->assertDatabaseHas('alert_events', [
            'alert_channel_id' => $channel->id,
            'event' => 'test',
            'delivered' => true,
        ]);
    }

    public function test_test_delivery_failures_are_recorded(): void
    {
        $this->fakeHostTelemetry();
        Http::fake([
            'https://hooks.example.com/serverdeck' => Http::response(['error' => 'down'], 500),
        ]);

        $channel = AlertChannel::factory()->webhook()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post("/alerts/channels/{$channel->id}/test")
            ->assertRedirect()
            ->assertSessionHas('error');

        $channel->refresh();

        $this->assertNotNull($channel->last_error);
        $this->assertStringContainsString('500', $channel->last_error);
        $this->assertDatabaseHas('alert_events', [
            'alert_channel_id' => $channel->id,
            'event' => 'test',
            'delivered' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'alert.channel.test']);
    }

    public function test_alert_evaluation_deduplicates_transitions_and_sends_recovery(): void
    {
        Mail::fake();

        $channel = AlertChannel::factory()->create([
            'name' => 'Operations email',
            'notify_warning' => true,
            'notify_critical' => true,
            'notify_recovery' => true,
        ]);

        $report = $this->alertHealthReport('warning');

        $this->mock(HostHealthService::class, function (MockInterface $mock) use (&$report): void {
            $mock->shouldReceive('report')->andReturnUsing(function () use (&$report): array {
                return $report;
            });
            $mock->shouldReceive('summary')->andReturnUsing(function () use (&$report): array {
                return $report['summary'];
            });
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/alerts/run')->assertRedirect()->assertSessionHas('success');
        Mail::assertSentCount(1);
        $this->assertDatabaseHas('alert_states', [
            'check_key' => 'smart',
            'last_status' => 'warning',
            'last_notified_status' => 'warning',
        ]);

        $this->actingAs($admin)->post('/alerts/run')->assertRedirect();
        Mail::assertSentCount(1);

        $report = $this->alertHealthReport('critical');
        $this->actingAs($admin)->post('/alerts/run')->assertRedirect();
        Mail::assertSentCount(2);
        $this->assertDatabaseHas('alert_states', [
            'check_key' => 'smart',
            'last_status' => 'critical',
            'last_notified_status' => 'critical',
        ]);

        $this->actingAs($admin)->post('/alerts/run')->assertRedirect();
        Mail::assertSentCount(2);

        $report = $this->alertHealthReport('ok');
        $this->actingAs($admin)->post('/alerts/run')->assertRedirect();
        Mail::assertSentCount(3);

        $state = AlertState::query()->where('check_key', 'smart')->firstOrFail();

        $this->assertSame('ok', $state->last_status);
        $this->assertSame('recovery', $state->last_notified_status);
        $this->assertNotNull($state->resolved_at);
        $this->assertDatabaseHas('alert_events', [
            'alert_channel_id' => $channel->id,
            'event' => 'recovery',
            'delivered' => true,
        ]);

        $this->actingAs($admin)->post('/alerts/run')->assertRedirect();
        Mail::assertSentCount(3);
    }

    public function test_checks_without_a_matching_channel_are_skipped(): void
    {
        Mail::fake();

        AlertChannel::factory()->criticalOnly()->create();

        $report = $this->alertHealthReport('warning');

        $this->mock(HostHealthService::class, function (MockInterface $mock) use (&$report): void {
            $mock->shouldReceive('report')->andReturnUsing(function () use (&$report): array {
                return $report;
            });
            $mock->shouldReceive('summary')->andReturnUsing(function () use (&$report): array {
                return $report['summary'];
            });
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->artisan('alerts:run')
            ->expectsOutputToContain('Evaluated 1 checks')
            ->assertSuccessful();

        Mail::assertNothingSent();

        $this->assertDatabaseHas('alert_states', [
            'check_key' => 'smart',
            'last_status' => 'warning',
            'last_notified_status' => null,
        ]);
    }

    public function test_the_alerts_command_evaluates_checks(): void
    {
        Mail::fake();

        AlertChannel::factory()->create(['name' => 'Operations email']);

        $report = $this->alertHealthReport('critical');

        $this->mock(HostHealthService::class, function (MockInterface $mock) use (&$report): void {
            $mock->shouldReceive('report')->andReturnUsing(function () use (&$report): array {
                return $report;
            });
            $mock->shouldReceive('summary')->andReturnUsing(function () use (&$report): array {
                return $report['summary'];
            });
            $mock->shouldReceive('forget')->zeroOrMoreTimes();
        });

        $this->artisan('alerts:run')
            ->expectsOutputToContain('1 triggered')
            ->assertSuccessful();

        Mail::assertSent(AlertTriggered::class);
        $this->assertDatabaseHas('alert_states', [
            'check_key' => 'smart',
            'last_status' => 'critical',
            'last_notified_status' => 'critical',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function alertHealthReport(string $status): array
    {
        $alerting = in_array($status, ['warning', 'critical'], true);
        $check = [
            'key' => 'smart',
            'title' => 'SMART disk health',
            'value' => $alerting ? 1 : 0,
            'status' => $status,
            'detail' => $alerting ? '1 disk needs attention (sdc)' : '8 disks · all healthy',
            'href' => '/disks',
        ];

        $report = $this->healthReport();
        $report['checks'] = [$check];
        $report['summary'] = [
            ...$report['summary'],
            'total' => 1,
            'ok' => $status === 'ok' ? 1 : 0,
            'warning' => $status === 'warning' ? 1 : 0,
            'critical' => $status === 'critical' ? 1 : 0,
            'info' => $status === 'info' ? 1 : 0,
            'overall' => $status,
            'attention' => $alerting ? 1 : 0,
            'active' => $alerting ? [$check] : [],
        ];

        return $report;
    }
}
