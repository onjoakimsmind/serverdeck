<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Services\Host\HostHealthService;
use App\Services\Host\HostInventoryService;
use Inertia\Inertia;
use Inertia\Response;

class HealthController extends Controller
{
    public function __invoke(HostHealthService $health, HostInventoryService $inventory): Response
    {
        return Inertia::render('Health/Index', [
            'report' => $health->report(),
            'stack' => $inventory->overview(),
        ]);
    }
}
