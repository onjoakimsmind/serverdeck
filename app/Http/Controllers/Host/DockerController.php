<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Host\DockerService;
use App\Services\Host\HostHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DockerController extends Controller
{
    public function index(DockerService $docker): Response
    {
        return Inertia::render('Docker/Index', [
            'docker' => $docker->overview(),
        ]);
    }

    public function action(Request $request, DockerService $docker, HostHealthService $health, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['start', 'stop', 'restart', 'pause', 'unpause', 'remove'])],
            'container' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
        ]);

        try {
            $docker->action($validated['action'], $validated['container']);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $health->forget();
        $audit->log('docker.'.$validated['action'], "{$request->user()->name} ran docker {$validated['action']} on {$validated['container']}", null, $validated);

        return back()->with('success', "Docker {$validated['action']} completed for {$validated['container']}.");
    }

    public function logs(Request $request, DockerService $docker): JsonResponse
    {
        $validated = $request->validate([
            'container' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
            'tail' => ['nullable', 'integer', 'min:1', 'max:2000'],
        ]);

        try {
            $data = $docker->logs($validated['container'], (int) ($validated['tail'] ?? 250));
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($data);
    }

    public function updateDevices(Request $request, DockerService $docker, HostHealthService $health, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'container' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
            'devices' => ['present', 'array', 'max:32'],
            'devices.*.host' => ['required', 'string', 'max:200', 'regex:/^\/dev\/[A-Za-z0-9_.:+@\/-]+$/'],
            'devices.*.container' => ['required', 'string', 'max:200', 'starts_with:/'],
            'devices.*.permissions' => ['required', 'string', 'regex:/^[rwm]{1,3}$/'],
            'gpus' => ['required', 'boolean'],
        ]);

        $devices = collect($validated['devices'])
            ->map(fn (array $device) => [
                'host' => (string) $device['host'],
                'container' => (string) $device['container'],
                'permissions' => (string) $device['permissions'],
            ])
            ->values()
            ->all();

        try {
            $result = $docker->updateDevices($validated['container'], $devices, (bool) $validated['gpus']);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $health->forget();
        $audit->log(
            'docker.devices',
            "{$request->user()->name} updated devices for Docker container {$validated['container']}",
            null,
            [
                'container' => $validated['container'],
                'devices' => $devices,
                'gpus' => (bool) $validated['gpus'],
                'recreated_id' => $result['id'] ?? null,
                'cleanup_error' => $result['cleanup_error'] ?? null,
            ],
        );

        $message = 'Devices updated. The container was recreated with the same configuration and remains stopped.';

        if (! empty($result['cleanup_error'])) {
            $message .= " The previous container is still present as {$result['previous']}.";
        }

        return response()->json([
            'message' => $message,
            'container' => $result['container'] ?? $validated['container'],
            'id' => $result['id'] ?? null,
            'cleanup_error' => $result['cleanup_error'] ?? null,
        ]);
    }
}
