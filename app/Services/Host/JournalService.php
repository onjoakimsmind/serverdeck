<?php

namespace App\Services\Host;

use App\Exceptions\HostCommandException;
use Illuminate\Support\Carbon;

class JournalService
{
    public function __construct(private readonly PrivilegedHost $host) {}

    public function entries(array $filters = [], int $lines = 150): array
    {
        $payload = ['lines' => $lines];

        foreach (['unit', 'priority', 'search'] as $key) {
            if (! empty($filters[$key])) {
                $payload[$key] = $key === 'search' ? preg_quote((string) $filters[$key], '/') : $filters[$key];
            }
        }

        try {
            $data = $this->host->run('journal.read', $payload, 30);
        } catch (HostCommandException $exception) {
            return [
                'available' => false,
                'error' => $exception->getMessage(),
                'entries' => collect(),
            ];
        }

        $levels = ['0' => 'error', '1' => 'error', '2' => 'error', '3' => 'error', '4' => 'warning', '5' => 'info', '6' => 'info', '7' => 'debug'];
        $priorityLabels = ['0' => 'emerg', '1' => 'alert', '2' => 'crit', '3' => 'err', '4' => 'warning', '5' => 'notice', '6' => 'info', '7' => 'debug'];

        $entries = collect($data['entries'] ?? [])
            ->map(function (array $entry) use ($levels, $priorityLabels) {
                $priority = (string) ($entry['priority'] ?? '6');
                $microseconds = (int) ($entry['timestamp'] ?? 0);

                return [
                    'timestamp' => $microseconds > 0
                        ? Carbon::createFromTimestamp((int) floor($microseconds / 1_000_000))->toIso8601String()
                        : null,
                    'level' => $levels[$priority] ?? 'info',
                    'priority' => $priorityLabels[$priority] ?? $priority,
                    'unit' => $entry['unit'] ?? null,
                    'source' => $entry['identifier'] ?? null,
                    'pid' => $entry['pid'] ?? null,
                    'hostname' => $entry['hostname'] ?? null,
                    'message' => (string) ($entry['message'] ?? ''),
                ];
            })
            ->reverse()
            ->values();

        return [
            'available' => true,
            'error' => null,
            'entries' => $entries,
        ];
    }

    public function units(): array
    {
        $result = $this->entries(['lines' => 400], 400);
        $units = collect($result['entries'])->pluck('unit')->filter()->unique()->sort()->values();

        return [
            'units' => $units,
            'available' => $result['available'],
            'error' => $result['error'],
        ];
    }
}
