<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\BackupJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BackupJob extends Model
{
    /** @use HasFactory<BackupJobFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'kind',
        'target',
        'frequency',
        'hour',
        'minute',
        'weekday',
        'recursive',
        'retention',
        'enabled',
        'last_run_at',
        'last_status',
        'last_error',
        'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'hour' => 'integer',
            'minute' => 'integer',
            'weekday' => 'integer',
            'recursive' => 'boolean',
            'retention' => 'integer',
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BackupRun::class);
    }

    public function snapshotPrefix(): string
    {
        return "serverdeck-job{$this->id}-";
    }

    public function nextRunAfter(?CarbonInterface $from = null): CarbonImmutable
    {
        $from = CarbonImmutable::instance($from ?? now())->setSecond(0);

        $candidate = match ($this->frequency) {
            'hourly' => $from->startOfHour()->addMinutes($this->minute),
            'weekly' => $from->startOfWeek(CarbonImmutable::MONDAY)
                ->addDays(max(0, ($this->weekday ?? 1) - 1))
                ->setTime($this->hour, $this->minute),
            'monthly' => $from->startOfMonth()->setTime($this->hour, $this->minute),
            default => $from->setTime($this->hour, $this->minute),
        };

        if ($candidate->lessThanOrEqualTo($from)) {
            $candidate = match ($this->frequency) {
                'hourly' => $candidate->addHour(),
                'weekly' => $candidate->addWeek(),
                'monthly' => $candidate->addMonthNoOverflow(),
                default => $candidate->addDay(),
            };
        }

        return $candidate;
    }

    public function scheduleLabel(): string
    {
        $time = sprintf('%02d:%02d', $this->hour, $this->minute);

        return match ($this->frequency) {
            'hourly' => sprintf('Hourly at :%02d', $this->minute),
            'weekly' => sprintf('Weekly on %s at %s', ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][max(0, ($this->weekday ?? 1) - 1)], $time),
            'monthly' => sprintf('Monthly on the 1st at %s', $time),
            default => sprintf('Daily at %s', $time),
        };
    }
}
