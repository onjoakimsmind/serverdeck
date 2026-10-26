<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Host\HostHealthService;
use App\Services\Host\SystemdService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ServicesController extends Controller
{
    public function index(Request $request, SystemdService $systemd): Response
    {
        $overview = $systemd->overview();
        $filter = in_array($request->input('filter'), ['all', 'running', 'failed', 'inactive'], true)
            ? $request->input('filter')
            : 'all';
        $search = $request->string('search')->trim()->value() ?: null;

        $units = collect($overview['units'])
            ->when($filter === 'running', fn ($units) => $units->where('active', 'active'))
            ->when($filter === 'failed', fn ($units) => $units->where('active', 'failed'))
            ->when($filter === 'inactive', fn ($units) => $units->where('active', 'inactive'))
            ->when($search, fn ($units) => $units->filter(
                fn ($unit) => str_contains(strtolower($unit['unit'].' '.$unit['description']), strtolower($search)),
            ))
            ->values();

        return Inertia::render('Services/Index', [
            'systemd' => [
                'available' => $overview['available'],
                'error' => $overview['error'],
                'counts' => $overview['counts'],
                'units' => $units,
                'matched' => $units->count(),
            ],
            'filters' => ['filter' => $filter, 'search' => $search],
        ]);
    }

    public function action(Request $request, SystemdService $systemd, HostHealthService $health, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['start', 'stop', 'restart', 'reload', 'enable', 'disable'])],
            'unit' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9@_.:-]+\.service$/'],
        ]);

        try {
            $systemd->action($validated['action'], $validated['unit']);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $health->forget();
        $audit->log('service.'.$validated['action'], "{$request->user()->name} ran systemctl {$validated['action']} on {$validated['unit']}", null, $validated);

        return back()->with('success', "systemctl {$validated['action']} completed for {$validated['unit']}.");
    }
}
