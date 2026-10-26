<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Host\HostHealthService;
use App\Services\Host\UpdateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class UpdateController extends Controller
{
    public function index(UpdateService $updates): Response
    {
        return Inertia::render('Updates/Index', [
            'updates' => $updates->overview(),
        ]);
    }

    public function refresh(Request $request, UpdateService $updates, HostHealthService $health, AuditLogger $audit): RedirectResponse
    {
        try {
            $report = $updates->refresh();
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $health->forget();

        $manager = $report['manager'] ?? 'packages';
        $label = $report['manager_label'] ?? 'package';
        $audit->log(
            "{$manager}.refresh",
            "{$request->user()->name} refreshed {$label} package lists on the host",
            null,
            ['lists_updated_at' => $report['lists_updated_at'] ?? null],
        );

        return back()->with(
            'success',
            sprintf(
                'Package lists refreshed. %d update%s available.',
                $report['counts']['total'] ?? 0,
                ($report['counts']['total'] ?? 0) === 1 ? '' : 's',
            ),
        );
    }

    public function upgrade(Request $request, UpdateService $updates, HostHealthService $health, AuditLogger $audit): RedirectResponse
    {
        @set_time_limit(0);

        $before = $updates->overview();
        $manager = $before['manager'] ?? 'packages';
        $label = $before['manager_label'] ?? 'package';
        $packages = collect($before['packages'] ?? [])->pluck('name')->take(100)->values()->all();

        try {
            $result = $updates->upgrade();
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $health->forget();

        $audit->log(
            "{$manager}.upgrade",
            sprintf(
                '%s installed %d package update%s on the host with %s',
                $request->user()->name,
                count($packages),
                count($packages) === 1 ? '' : 's',
                $label,
            ),
            null,
            [
                'packages' => $packages,
                'security' => $before['counts']['security'] ?? 0,
                'reboot_required' => (bool) ($result['upgrade']['reboot_required'] ?? false),
                'output_tail' => $result['upgrade']['output_tail'] ?? null,
            ],
        );

        $message = $packages === []
            ? 'No updates were pending.'
            : sprintf('Installed %d package update%s.', count($packages), count($packages) === 1 ? '' : 's');

        if ($result['upgrade']['reboot_required'] ?? false) {
            $message .= ' A reboot is required to finish applying kernel or library updates.';
        }

        return back()->with('success', $message);
    }
}
