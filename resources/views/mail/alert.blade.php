<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $payload['title'] ?? 'ServerDeck alert' }}</title>
</head>
@php
    $status = $payload['status'] ?? 'warning';
    $colors = [
        'warning' => ['#f59e0b', 'rgba(245, 158, 11, 0.12)'],
        'critical' => ['#f43f5e', 'rgba(244, 63, 94, 0.12)'],
        'recovery' => ['#22c55e', 'rgba(34, 197, 94, 0.12)'],
        'test' => ['#06b6d4', 'rgba(6, 182, 212, 0.12)'],
    ];
    [$accent, $accentBackground] = $colors[$status] ?? $colors['warning'];
    $link = rtrim((string) config('app.url'), '/').($payload['href'] ?? '/alerts');
@endphp
<body style="margin: 0; padding: 24px; background: #070b10; color: #e5edf5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif;">
    <div style="max-width: 640px; margin: 0 auto; border: 1px solid #1c2a38; border-radius: 14px; overflow: hidden; background: #0b141e;">
        <div style="border-bottom: 1px solid #1c2a38; padding: 18px 22px; background: {{ $accentBackground }};">
            <div style="font-size: 11px; font-weight: 700; letter-spacing: 0.16em; text-transform: uppercase; color: {{ $accent }};">
                ServerDeck · {{ $status }}
            </div>
            <h1 style="margin: 8px 0 0; font-size: 19px; line-height: 1.35; color: #f8fafc;">
                {{ $payload['title'] ?? 'Host alert' }}
            </h1>
            <p style="margin: 4px 0 0; font-size: 12px; color: #94a3b8;">
                {{ $payload['hostname'] ?? 'This host' }} · {{ $payload['sent_at'] ?? now()->toIso8601String() }}
            </p>
        </div>

        <div style="padding: 22px;">
            <p style="margin: 0 0 14px; font-size: 14px; line-height: 1.7; color: #cbd5e1;">
                {{ $payload['message'] ?? 'A host health check changed state.' }}
            </p>

            @if (! empty($payload['detail']))
                <div style="margin: 0 0 18px; border: 1px solid #1c2a38; border-radius: 10px; background: #0f1a24; padding: 13px 15px;">
                    <div style="font-size: 10px; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: #64748b;">Detail</div>
                    <p style="margin: 5px 0 0; font-size: 12px; line-height: 1.6; color: #cbd5e1;">{{ $payload['detail'] }}</p>
                </div>
            @endif

            <a href="{{ $link }}" style="display: inline-block; border-radius: 8px; background: {{ $accent }}; padding: 9px 15px; font-size: 12px; font-weight: 700; color: #08111a; text-decoration: none;">
                Open in ServerDeck
            </a>
        </div>

        <div style="border-top: 1px solid #1c2a38; padding: 13px 22px; font-size: 11px; line-height: 1.6; color: #64748b;">
            Sent by ServerDeck because this alert channel is enabled. State transitions are deduplicated; a recovery message is sent when the check returns to normal.
        </div>
    </div>
</body>
</html>
