<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Services\Host\DeviceInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function index(Request $request, DeviceInventoryService $devices): JsonResponse
    {
        $refresh = $request->boolean('refresh') && ($request->user()?->canOperate() ?? false);

        return response()->json($devices->overview($refresh));
    }
}
