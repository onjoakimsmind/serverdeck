<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AlertState extends Model
{
    use HasFactory;

    protected $fillable = [
        'check_key',
        'check_title',
        'last_status',
        'last_notified_status',
        'first_seen_at',
        'last_seen_at',
        'last_notified_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_notified_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->last_status, ['warning', 'critical'], true) && $this->resolved_at === null;
    }
}
