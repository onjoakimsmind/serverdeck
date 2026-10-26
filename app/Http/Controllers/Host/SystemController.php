<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Host\HostHealthService;
use App\Services\Host\HostMetricsService;
use App\Services\Host\ProcessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemController extends Controller
{
    public function index(Request $request, HostMetricsService $metrics, ProcessService $processes): Response
    {
        $sort = in_array($request->input('sort'), ['cpu', 'memory', 'pid', 'time'], true) ? $request->input('sort') : 'cpu';
        $search = $request->string('search')->trim()->value() ?: null;

        return Inertia::render('System/Index', [
            'metrics' => $metrics->snapshot(),
            'processes' => $processes->list($sort, $search),
            'filters' => ['sort' => $sort, 'search' => $search],
        ]);
    }

    public function signal(Request $request, int $pid, ProcessService $processes, HostHealthService $health, AuditLogger $audit): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'signal' => ['required', 'in:TERM,KILL,HUP,INT'],
        ]);

        try {
            $processes->kill($pid, $validated['signal']);
        } catch (\Throwable $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return back()->with('error', $exception->getMessage());
        }

        $health->forget();
        $audit->log('process.signal', "{$request->user()->name} sent SIG{$validated['signal']} to PID {$pid}", null, [
            'pid' => $pid,
            'signal' => $validated['signal'],
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', "Sent SIG{$validated['signal']} to PID {$pid}.");
    }
}
