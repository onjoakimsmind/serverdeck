<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MetricSample extends Model
{
    use HasFactory;

    protected $fillable = [
        'recorded_at',
        'cpu_usage',
        'memory_percent',
        'swap_percent',
        'disk_percent',
        'load_1',
        'load_5',
        'load_15',
        'network_rx_rate',
        'network_tx_rate',
        'processes',
        'temperature_celsius',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'cpu_usage' => 'float',
            'memory_percent' => 'float',
            'swap_percent' => 'float',
            'disk_percent' => 'float',
            'load_1' => 'float',
            'load_5' => 'float',
            'load_15' => 'float',
            'network_rx_rate' => 'float',
            'network_tx_rate' => 'float',
            'processes' => 'integer',
            'temperature_celsius' => 'float',
        ];
    }
}
