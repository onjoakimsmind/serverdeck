<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Host\HostHealthService;
use App\Services\Host\SmartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DiskController extends Controller
{
    public function index(SmartService $smart): Response
    {
        return Inertia::render('Disks/Index', [
            'smart' => $smart->overview(),
        ]);
    }

    public function scan(SmartService $smart, HostHealthService $health): RedirectResponse
    {
        $report = $smart->overview(true);
        $health->forget();

        if (! $report['available']) {
            return back()->with('error', 'SMART scan failed: '.$report['error']);
        }

        return back()->with(
            'success',
            sprintf('SMART data refreshed for %d disk%s.', $report['counts']['total'], $report['counts']['total'] === 1 ? '' : 's'),
        );
    }

    public function selfTest(Request $request, SmartService $smart, HostHealthService $health, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'device' => ['required', 'string', 'max:80', 'regex:/^\/dev\/[A-Za-z0-9_.-]+$/'],
            'mode' => ['required', Rule::in(['short', 'long'])],
        ]);

        try {
            $smart->startSelfTest($validated['device'], $validated['mode']);
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $smart->forget();
        $health->forget();

        $audit->log(
            'smart.selftest',
            sprintf(
                '%s started a %s SMART self-test on %s',
                $request->user()->name,
                $validated['mode'],
                $validated['device'],
            ),
            null,
            $validated,
        );

        return back()->with(
            'success',
            sprintf('A %s self-test started on %s. Refresh SMART data to follow its progress.', $validated['mode'], $validated['device']),
        );
    }

    public function abortSelfTest(Request $request, SmartService $smart, HostHealthService $health, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'device' => ['required', 'string', 'max:80', 'regex:/^\/dev\/[A-Za-z0-9_.-]+$/'],
        ]);

        try {
            $smart->abortSelfTest($validated['device']);
        } catch (Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $smart->forget();
        $health->forget();

        $audit->log(
            'smart.selftest.abort',
            "{$request->user()->name} aborted the SMART self-test on {$validated['device']}",
            null,
            $validated,
        );

        return back()->with('success', "The self-test on {$validated['device']} was aborted.");
    }
}
