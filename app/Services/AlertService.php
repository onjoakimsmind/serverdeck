<?php

namespace App\Services;

use App\Mail\AlertTriggered;
use App\Models\AlertChannel;
use App\Models\AlertEvent;
use App\Models\AlertState;
use App\Services\Host\HostHealthService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AlertService
{
    private const LAST_RUN_KEY = 'alerts.last_run_at';

    private const EVENT_RETENTION_DAYS = 90;

    public function __construct(private readonly HostHealthService $health) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(bool $includeDestinations = false): array
    {
        $report = $this->health->report();
        $checks = collect($report['checks'])->keyBy('key');

        $channels = AlertChannel::query()->orderBy('name')->get();
        $alerts = AlertState::query()
            ->orderByDesc('last_seen_at')
            ->limit(25)
            ->get()
            ->map(function (AlertState $state) use ($checks): array {
                $check = $checks->get($state->check_key);

                return [
                    'check_key' => $state->check_key,
                    'check_title' => $check['title'] ?? $state->check_title,
                    'status' => $check['status'] ?? $state->last_status,
                    'detail' => $check['detail'] ?? null,
                    'href' => $check['href'] ?? null,
                    'value' => $check['value'] ?? null,
                    'first_seen_at' => $state->first_seen_at?->toIso8601String(),
                    'last_seen_at' => $state->last_seen_at?->toIso8601String(),
                    'last_notified_status' => $state->last_notified_status,
                    'last_notified_at' => $state->last_notified_at?->toIso8601String(),
                    'resolved_at' => $state->resolved_at?->toIso8601String(),
                ];
            })
            ->filter(fn (array $alert) => in_array($alert['status'], ['warning', 'critical'], true)
                || in_array($alert['last_notified_status'], ['warning', 'critical'], true)
                || $alert['resolved_at'] !== null)
            ->take(12)
            ->values();

        $events = AlertEvent::query()
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (AlertEvent $event): array => [
                'id' => $event->id,
                'channel_name' => $event->channel_name,
                'event' => $event->event,
                'check_key' => $event->check_key,
                'check_title' => $event->check_title,
                'status' => $event->status,
                'message' => $event->message,
                'delivered' => $event->delivered,
                'error' => $event->error,
                'created_at' => $event->created_at?->toIso8601String(),
            ]);

        return [
            'channels' => $channels
                ->map(fn (AlertChannel $channel): array => [
                    'id' => $channel->id,
                    'name' => $channel->name,
                    'type' => $channel->type,
                    'type_label' => $channel->typeLabel(),
                    'destination' => $includeDestinations ? $channel->destination : null,
                    'destination_hint' => $channel->destinationHint(),
                    'enabled' => $channel->enabled,
                    'notify_warning' => $channel->notify_warning,
                    'notify_critical' => $channel->notify_critical,
                    'notify_recovery' => $channel->notify_recovery,
                    'last_sent_at' => $channel->last_sent_at?->toIso8601String(),
                    'last_error' => $channel->last_error,
                ])
                ->values(),
            'alerts' => $alerts,
            'events' => $events,
            'counts' => [
                'channels' => $channels->count(),
                'enabled' => $channels->where('enabled', true)->count(),
                'open' => $report['summary']['attention'] ?? 0,
                'critical' => $report['summary']['critical'] ?? 0,
                'deliveries_24h' => AlertEvent::query()
                    ->where('delivered', true)
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
                'failures_24h' => AlertEvent::query()
                    ->where('delivered', false)
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
            ],
            'mail' => [
                'driver' => config('mail.default'),
                'from' => config('mail.from.address'),
                'log_only' => config('mail.default') === 'log',
            ],
            'last_run_at' => Cache::get(self::LAST_RUN_KEY),
        ];
    }

    /**
     * Evaluate every health check and notify on state transitions only.
     *
     * @return array{evaluated: int, triggered: int, recovered: int, skipped: int, deliveries: int, failed: int}
     */
    public function evaluate(): array
    {
        $report = $this->health->report();
        $channels = AlertChannel::query()->where('enabled', true)->get();

        $counts = [
            'evaluated' => 0,
            'triggered' => 0,
            'recovered' => 0,
            'skipped' => 0,
            'deliveries' => 0,
            'failed' => 0,
        ];

        foreach ($report['checks'] as $check) {
            $counts['evaluated']++;

            $state = AlertState::query()->firstOrNew(['check_key' => $check['key']]);
            $previousStatus = $state->last_status;
            $state->check_title = $check['title'];
            $state->last_status = $check['status'];
            $state->last_seen_at = now();

            $isAlerting = in_array($check['status'], ['warning', 'critical'], true);
            $wasAlerting = in_array($state->last_notified_status, ['warning', 'critical'], true);

            if ($isAlerting) {
                $state->resolved_at = null;

                if ($state->first_seen_at === null) {
                    $state->first_seen_at = now();
                }

                if ($state->last_notified_status === $check['status']) {
                    $counts['skipped']++;
                    $state->save();

                    continue;
                }

                $eligible = $channels->filter(
                    fn (AlertChannel $channel) => $channel->notifies($check['status']),
                );

                if ($eligible->isEmpty()) {
                    $counts['skipped']++;
                    $state->save();

                    continue;
                }

                $result = $this->deliver($eligible, 'alert', $check);
                $counts['deliveries'] += $result['delivered'];
                $counts['failed'] += $result['failed'];

                if ($result['delivered'] > 0) {
                    $state->last_notified_status = $check['status'];
                    $state->last_notified_at = now();
                    $counts['triggered']++;
                }
            } elseif ($wasAlerting) {
                $eligible = $channels->filter(
                    fn (AlertChannel $channel) => $channel->notifies('recovery'),
                );

                $recoveryHandled = $eligible->isEmpty();

                if ($eligible->isNotEmpty()) {
                    $result = $this->deliver($eligible, 'recovery', [
                        ...$check,
                        'status' => 'recovery',
                    ]);
                    $counts['deliveries'] += $result['delivered'];
                    $counts['failed'] += $result['failed'];
                    $recoveryHandled = $result['delivered'] > 0;

                    if ($recoveryHandled) {
                        $counts['recovered']++;
                    }
                }

                if ($recoveryHandled) {
                    $state->last_notified_status = 'recovery';
                    $state->first_seen_at = null;
                    $state->resolved_at = now();
                }
            } elseif (in_array($previousStatus, ['warning', 'critical'], true)) {
                $state->last_notified_status = null;
                $state->first_seen_at = null;
                $state->resolved_at = now();
            }

            $state->save();
        }

        Cache::forever(self::LAST_RUN_KEY, now()->toIso8601String());
        AlertEvent::query()
            ->where('created_at', '<', now()->subDays(self::EVENT_RETENTION_DAYS))
            ->delete();

        return $counts;
    }

    public function sendTest(AlertChannel $channel): void
    {
        $check = [
            'key' => 'test',
            'title' => 'Test notification',
            'status' => 'test',
            'value' => 0,
            'detail' => "This is a test notification for {$channel->name}. Alert delivery is configured correctly.",
            'href' => '/alerts',
        ];

        $result = $this->deliver(collect([$channel]), 'test', $check);

        if ($result['failed'] > 0) {
            throw new RuntimeException($channel->last_error ?: 'The test notification could not be delivered.');
        }
    }

    /**
     * @param  Collection<int, AlertChannel>  $channels
     * @param  array<string, mixed>  $check
     * @return array{delivered: int, failed: int}
     */
    private function deliver(Collection $channels, string $event, array $check): array
    {
        $payload = $this->payload($event, $check);
        $result = ['delivered' => 0, 'failed' => 0];

        foreach ($channels as $channel) {
            try {
                $this->deliverToChannel($channel, $payload);

                $channel->forceFill([
                    'last_sent_at' => now(),
                    'last_error' => null,
                ])->save();

                $this->recordEvent($channel, $event, $check, $payload['message'], true);
                $result['delivered']++;
            } catch (Throwable $exception) {
                $error = Str::limit($exception->getMessage(), 1000, '…');

                $channel->forceFill(['last_error' => $error])->save();
                $this->recordEvent($channel, $event, $check, $payload['message'], false, $error);
                $result['failed']++;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deliverToChannel(AlertChannel $channel, array $payload): void
    {
        if ($channel->type === AlertChannel::TYPE_EMAIL) {
            $recipients = $channel->recipients();

            if ($recipients === []) {
                throw new RuntimeException('No valid email recipients are configured for this channel.');
            }

            Mail::to($recipients)->send(new AlertTriggered($payload));

            return;
        }

        if ($channel->type === AlertChannel::TYPE_WEBHOOK) {
            $url = (string) $channel->destination;

            if (! preg_match('#^https?://#i', $url)) {
                throw new RuntimeException('The webhook URL must use HTTP or HTTPS.');
            }

            Http::timeout(15)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'User-Agent' => 'ServerDeck/2.0',
                    'X-ServerDeck-Event' => (string) $payload['event'],
                ])
                ->post($url, $payload)
                ->throw();

            return;
        }

        throw new RuntimeException("Unsupported alert channel type [{$channel->type}].");
    }

    /**
     * @param  array<string, mixed>  $check
     * @return array<string, mixed>
     */
    private function payload(string $event, array $check): array
    {
        $status = (string) ($check['status'] ?? 'warning');
        $title = (string) ($check['title'] ?? 'Host alert');
        $detail = trim((string) ($check['detail'] ?? ''));

        $message = match ($event) {
            'recovery' => "{$title} has returned to normal.",
            'test' => "This is a test notification from ServerDeck for {$title}.",
            default => $detail !== ''
                ? "{$title} is {$status}: {$detail}"
                : "{$title} is {$status}.",
        };

        return [
            'event' => $event,
            'status' => $status,
            'title' => $title,
            'detail' => $detail,
            'value' => $check['value'] ?? null,
            'href' => $check['href'] ?? '/alerts',
            'hostname' => gethostname() ?: 'ServerDeck host',
            'message' => $message,
            'sent_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $check
     */
    private function recordEvent(
        AlertChannel $channel,
        string $event,
        array $check,
        string $message,
        bool $delivered,
        ?string $error = null,
    ): void {
        AlertEvent::create([
            'alert_channel_id' => $channel->id,
            'channel_name' => $channel->name,
            'event' => $event,
            'check_key' => (string) ($check['key'] ?? 'unknown'),
            'check_title' => (string) ($check['title'] ?? 'Host alert'),
            'status' => (string) ($check['status'] ?? 'warning'),
            'message' => $message,
            'delivered' => $delivered,
            'error' => $error,
        ]);
    }
}
