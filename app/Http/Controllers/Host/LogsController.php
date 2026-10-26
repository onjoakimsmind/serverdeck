<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Services\Host\JournalService;
use App\Services\Host\SystemdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LogsController extends Controller
{
    private const PRIORITIES = ['emerg', 'alert', 'crit', 'err', 'warning', 'notice', 'info', 'debug'];

    public function index(Request $request, JournalService $journal, SystemdService $systemd): Response
    {
        $filters = $this->filters($request);
        $result = $journal->entries($filters, 200);
        $units = collect($systemd->overview()['units'])->pluck('unit')->sort()->values();

        return Inertia::render('Logs/Index', [
            'journal' => [
                'available' => $result['available'],
                'error' => $result['error'],
                'entries' => $result['entries'],
            ],
            'filters' => $filters,
            'units' => $units,
            'priorities' => self::PRIORITIES,
            'summary' => $this->summary($result['entries']->all()),
        ]);
    }

    public function tail(Request $request, JournalService $journal): JsonResponse
    {
        $result = $journal->entries($this->filters($request), 200);

        return response()->json([
            'available' => $result['available'],
            'error' => $result['error'],
            'entries' => $result['entries'],
            'summary' => $this->summary($result['entries']->all()),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    private function filters(Request $request): array
    {
        return [
            'unit' => $request->string('unit')->trim()->value() ?: null,
            'priority' => in_array($request->input('priority'), self::PRIORITIES, true) ? $request->input('priority') : null,
            'search' => $request->string('search')->trim()->value() ?: null,
        ];
    }

    private function summary(array $entries): array
    {
        return [
            'total' => count($entries),
            'errors' => count(array_filter($entries, fn ($entry) => $entry['level'] === 'error')),
            'warnings' => count(array_filter($entries, fn ($entry) => $entry['level'] === 'warning')),
            'sources' => count(array_unique(array_filter(array_column($entries, 'source')))),
        ];
    }
}
