<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertChannel extends Model
{
    use HasFactory;

    public const TYPE_EMAIL = 'email';

    public const TYPE_WEBHOOK = 'webhook';

    public const TYPES = [self::TYPE_EMAIL, self::TYPE_WEBHOOK];

    protected $fillable = [
        'name',
        'type',
        'destination',
        'enabled',
        'notify_warning',
        'notify_critical',
        'notify_recovery',
        'last_sent_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'destination' => 'encrypted',
            'enabled' => 'boolean',
            'notify_warning' => 'boolean',
            'notify_critical' => 'boolean',
            'notify_recovery' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }

    /**
     * @return array<int, string>
     */
    public function recipients(): array
    {
        return collect(explode(',', (string) $this->destination))
            ->map(fn (string $email) => trim($email))
            ->filter(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->values()
            ->all();
    }

    public function destinationHint(): string
    {
        if ($this->type === self::TYPE_EMAIL) {
            $recipients = $this->recipients();

            if (count($recipients) > 1) {
                return count($recipients).' recipients';
            }

            $email = $recipients[0] ?? '';
            [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
            $masked = $local !== '' ? mb_substr($local, 0, 1).'***' : '***';

            return $domain !== '' ? "{$masked}@{$domain}" : $email;
        }

        $parts = parse_url((string) $this->destination);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'webhook';

        return "{$scheme}://{$host}";
    }

    public function notifies(string $status): bool
    {
        return match ($status) {
            'warning' => $this->notify_warning,
            'critical' => $this->notify_critical,
            'recovery' => $this->notify_recovery,
            default => false,
        };
    }

    public function typeLabel(): string
    {
        return $this->type === self::TYPE_EMAIL ? 'Email' : 'Webhook';
    }
}
