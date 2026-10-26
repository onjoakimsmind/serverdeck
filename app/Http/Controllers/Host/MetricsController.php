<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Services\Host\HostMetricsService;
use App\Services\Host\MetricHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MetricsController extends Controller
{
    public function __invoke(HostMetricsService $metrics): JsonResponse
    {
        return response()->json($metrics->snapshot());
    }

    public function history(Request $request, MetricHistoryService $history): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['sometimes', 'string', Rule::in(array_keys(MetricHistoryService::RANGES))],
        ]);

        return response()->json($history->history($validated['range'] ?? '24h'));
    }
}
