<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'alert_channel_id',
        'channel_name',
        'event',
        'check_key',
        'check_title',
        'status',
        'message',
        'delivered',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'delivered' => 'boolean',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(AlertChannel::class, 'alert_channel_id');
    }
}
