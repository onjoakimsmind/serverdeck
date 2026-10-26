<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Host\HostHealthService;
use App\Services\Host\HostMetricsService;
use App\Services\Host\ZfsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StorageController extends Controller
{
    public function index(ZfsService $zfs, HostMetricsService $metrics): Response
    {
        return Inertia::render('Storage/Index', [
            'zfs' => $zfs->overview(),
            'disks' => $metrics->disks(),
            'block' => $metrics->blockDevices(),
        ]);
    }

    public function action(Request $request, ZfsService $zfs, HostHealthService $health, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['scrub', 'create_dataset', 'destroy_dataset', 'snapshot', 'create_pool'])],
            'pool' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z][A-Za-z0-9_.-]*$/'],
            'name' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.:\/-]+$/'],
            'snapshot' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.:-]+$/'],
            'recursive' => ['nullable', 'boolean'],
            'devices' => ['nullable', 'array', 'max:12'],
            'devices.*' => ['string', 'max:120', 'regex:/^\/dev\/[A-Za-z0-9\/_.-]+$/'],
        ]);

        $action = $validated['action'];

        if (in_array($action, ['scrub', 'create_pool'], true)) {
            $request->validate(['pool' => ['required']]);
        }

        if (in_array($action, ['create_dataset', 'destroy_dataset', 'snapshot'], true)) {
            $request->validate(['name' => ['required']]);
        }

        if ($action === 'snapshot') {
            $request->validate(['snapshot' => ['required']]);
        }

        if ($action === 'create_pool') {
            $request->validate(['devices' => ['required', 'array', 'min:1']]);
        }

        $payload = array_filter([
            'action' => $action,
            'pool' => $validated['pool'] ?? null,
            'name' => $validated['name'] ?? null,
            'snapshot' => $validated['snapshot'] ?? null,
            'recursive' => (bool) ($validated['recursive'] ?? false),
            'devices' => $validated['devices'] ?? [],
        ], fn ($value) => $value !== null && $value !== []);

        try {
            $zfs->action($payload);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $health->forget();
        $audit->log('zfs.'.$action, "{$request->user()->name} ran ZFS {$action}", null, $payload);

        return back()->with('success', match ($action) {
            'scrub' => 'Scrub started on '.$payload['pool'].'.',
            'create_dataset' => 'Dataset '.$payload['name'].' created.',
            'destroy_dataset' => 'Dataset '.$payload['name'].' destroyed.',
            'snapshot' => 'Snapshot '.$payload['name'].'@'.$payload['snapshot'].' created.',
            'create_pool' => 'Pool '.$payload['pool'].' created.',
        });
    }
}
