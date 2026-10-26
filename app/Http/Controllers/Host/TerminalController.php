<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Host\HostMetricsService;
use Inertia\Inertia;
use Inertia\Response;

class TerminalController extends Controller
{
    public function index(HostMetricsService $metrics): Response
    {
        return Inertia::render('Terminal/Index', [
            'host' => $metrics->identity(),
            'sessions' => AuditLog::query()
                ->whereIn('action', ['console.open', 'console.close'])
                ->where('description', 'like', '%Host root shell%')
                ->with('user:id,name,username')
                ->latest('id')
                ->limit(12)
                ->get(['id', 'user_id', 'action', 'description', 'created_at']),
        ]);
    }
}
