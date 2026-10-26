<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Host\HostHealthService;
use App\Services\Host\IncusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class IncusController extends Controller
{
    public function index(IncusService $incus): Response
    {
        return Inertia::render('Incus/Index', [
            'incus' => $incus->overview(),
            'images' => $incus->images(),
        ]);
    }

    public function images(IncusService $incus): JsonResponse
    {
        return response()->json(['images' => $incus->images()]);
    }

    public function remoteImages(IncusService $incus): JsonResponse
    {
        return response()->json(['images' => $incus->remoteImages()]);
    }

    public function create(Request $request, IncusService $incus, HostHealthService $health, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:63', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'type' => ['required', Rule::in(['container', 'virtual-machine'])],
            'image' => ['required', 'string', 'regex:/^(?:[a-f0-9]{64}|images:[A-Za-z0-9][A-Za-z0-9._\/-]{0,120})$/'],
            'start' => ['required', 'boolean'],
        ]);

        $imageRef = (string) $validated['image'];
        $imageLabel = '';
        $imageSource = 'cached';
        $imageFingerprint = null;

        if (str_starts_with($imageRef, 'images:')) {
            $remote = collect($incus->remoteImages())->firstWhere('ref', $imageRef);
            $slot = $validated['type'] === 'virtual-machine' ? 'virtual_machine' : 'container';

            if (! $remote) {
                throw ValidationException::withMessages([
                    'image' => 'That image alias is not available from the Incus image server. Refresh the catalog and try again.',
                ]);
            }

            if (empty($remote[$slot])) {
                throw ValidationException::withMessages([
                    'image' => 'That image is not available for the selected instance type.',
                ]);
            }

            $imageLabel = (string) ($remote['label'] ?? $imageRef);
            $imageSource = 'remote';
        } else {
            $image = collect($incus->images())->firstWhere('fingerprint', $imageRef);

            if (! $image) {
                throw ValidationException::withMessages([
                    'image' => 'That image is not cached on this host anymore. Refresh the image list and try again.',
                ]);
            }

            if ($image['type'] !== $validated['type']) {
                throw ValidationException::withMessages([
                    'image' => 'The selected image does not match the instance type.',
                ]);
            }

            $imageLabel = $image['label'] ?: $imageRef;
            $imageFingerprint = $imageRef;
        }

        try {
            $incus->create($validated['name'], $validated['type'], $imageRef, $validated['start']);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $health->forget();
        $audit->log(
            'incus.create',
            sprintf(
                '%s created %s %s from %s',
                $request->user()->name,
                $validated['type'] === 'virtual-machine' ? 'VM' : 'container',
                $validated['name'],
                $imageLabel,
            ),
            null,
            [
                'name' => $validated['name'],
                'type' => $validated['type'],
                'image' => $imageRef,
                'image_fingerprint' => $imageFingerprint,
                'image_label' => $imageLabel,
                'image_source' => $imageSource,
                'started' => $validated['start'],
            ],
        );

        return back()->with(
            'success',
            sprintf(
                '%s %s was created%s.',
                $validated['type'] === 'virtual-machine' ? 'Virtual machine' : 'Container',
                $validated['name'],
                $validated['start'] ? ' and started' : '',
            ),
        );
    }

    public function detail(Request $request, IncusService $incus): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
        ]);

        try {
            return response()->json($incus->detail($validated['name']));
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function updateConfig(Request $request, IncusService $incus, HostHealthService $health, AuditLogger $audit): JsonResponse
    {
        $allowedKeys = ['limits.cpu', 'limits.memory', 'limits.processes', 'boot.autostart', 'security.nesting'];
        $patterns = [
            'limits.cpu' => '/^\d{1,3}(?:-\d{1,3})?(?:,\d{1,3}(?:-\d{1,3})?)*$/',
            'limits.memory' => '/^\d{1,4}(?:\.\d{1,2})?(?:B|kB|MB|GB|TB|KiB|MiB|GiB|TiB|%)$/',
            'limits.processes' => '/^\d{1,6}$/',
            'boot.autostart' => '/^(?:true|false)$/',
            'security.nesting' => '/^(?:true|false)$/',
        ];

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'config' => ['required', 'array'],
        ]);

        $rawConfig = $request->input('config', []);
        $unknown = array_values(array_diff(array_keys($rawConfig), $allowedKeys));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'config' => 'Unsupported configuration keys: '.implode(', ', $unknown),
            ]);
        }

        $config = [];

        foreach ($allowedKeys as $key) {
            if (! array_key_exists($key, $rawConfig)) {
                continue;
            }

            $value = match (true) {
                $rawConfig[$key] === null => '',
                is_bool($rawConfig[$key]) => $rawConfig[$key] ? 'true' : 'false',
                is_int($rawConfig[$key]), is_float($rawConfig[$key]) => (string) $rawConfig[$key],
                is_string($rawConfig[$key]) => trim($rawConfig[$key]),
                default => throw ValidationException::withMessages([$key => "Invalid value for {$key}."]),
            };

            if ($value !== '' && (strlen($value) > 64 || preg_match($patterns[$key], $value) !== 1)) {
                throw ValidationException::withMessages([$key => "Invalid value for {$key}."]);
            }

            if ($key === 'limits.processes' && $value !== '' && ((int) $value < 1 || (int) $value > 100000)) {
                throw ValidationException::withMessages([$key => 'The process limit must be between 1 and 100000.']);
            }

            if ($key === 'limits.memory' && str_ends_with($value, '%')) {
                $percent = (int) rtrim($value, '%');

                if ($percent < 1 || $percent > 100) {
                    throw ValidationException::withMessages([$key => 'The memory percentage must be between 1 and 100.']);
                }
            }

            $config[$key] = $value;
        }

        try {
            $result = $incus->updateConfig($validated['name'], $config, (string) ($validated['description'] ?? ''));
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $health->forget();
        $audit->log(
            'incus.config',
            "{$request->user()->name} updated configuration for Incus instance {$validated['name']}",
            null,
            [
                'name' => $validated['name'],
                'keys' => $result['changed'] ?? array_keys($config),
                'description' => (string) ($validated['description'] ?? ''),
            ],
        );

        return response()->json([
            'message' => 'Configuration saved.',
            'changed' => $result['changed'] ?? [],
        ]);
    }

    public function addDevice(Request $request, IncusService $incus, HostHealthService $health, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
            'device' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
            'type' => ['required', Rule::in(['nic', 'disk', 'unix-char', 'unix-block', 'gpu', 'usb', 'pci'])],
            'network' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
            'parent' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
            'vlan' => ['nullable', 'integer', 'min:1', 'max:4094'],
            'mtu' => ['nullable', 'integer', 'min:576', 'max:65535'],
            'source_type' => ['nullable', Rule::in(['path', 'volume'])],
            'source' => ['nullable', 'string', 'max:255'],
            'path' => ['nullable', 'string', 'max:255', 'starts_with:/'],
            'pool' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'volume' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'readonly' => ['nullable', 'boolean'],
            'gputype' => ['nullable', Rule::in(['physical'])],
            'pci' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{4}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2}\.[0-7]$/'],
            'address' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{4}:[0-9a-fA-F]{2}:[0-9a-fA-F]{2}\.[0-7]$/'],
            'vendor_id' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{4}$/'],
            'product_id' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{4}$/'],
            'serial' => ['nullable', 'string', 'max:128'],
            'bus' => ['nullable', 'integer', 'min:1', 'max:999'],
            'devnum' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        if (in_array($validated['type'], ['unix-char', 'unix-block'], true) && empty($validated['source'])) {
            throw ValidationException::withMessages([
                'source' => 'Choose a host device node to pass through.',
            ]);
        }

        if ($validated['type'] === 'gpu' && empty($validated['pci'])) {
            throw ValidationException::withMessages([
                'pci' => 'Choose a host GPU by PCI address.',
            ]);
        }

        if ($validated['type'] === 'pci' && empty($validated['address'])) {
            throw ValidationException::withMessages([
                'address' => 'Choose a host PCI device by address.',
            ]);
        }

        if ($validated['type'] === 'usb' && (empty($validated['vendor_id']) || empty($validated['product_id']))) {
            throw ValidationException::withMessages([
                'vendor_id' => 'Choose a USB device with a vendor and product ID.',
            ]);
        }

        if ($validated['type'] === 'nic' && (bool) ($validated['network'] ?? false) === (bool) ($validated['parent'] ?? false)) {
            throw ValidationException::withMessages([
                'parent' => 'Choose either a managed Incus network or a host bridge.',
            ]);
        }

        if ($validated['type'] === 'disk') {
            if ($validated['source_type'] === 'path') {
                if (empty($validated['source']) || empty($validated['path'])) {
                    throw ValidationException::withMessages([
                        'source' => 'A host path and a mount path are required for a bind mount.',
                    ]);
                }
            } elseif ($validated['source_type'] === 'volume') {
                if (empty($validated['pool']) || empty($validated['volume'])) {
                    throw ValidationException::withMessages([
                        'volume' => 'Choose a storage pool and volume.',
                    ]);
                }
            } else {
                throw ValidationException::withMessages([
                    'source_type' => 'Choose a host path or a storage volume.',
                ]);
            }
        }

        try {
            $incus->addDevice($validated['name'], $validated);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $health->forget();
        $audit->log(
            'incus.device.add',
            "{$request->user()->name} added {$validated['type']} device {$validated['device']} to {$validated['name']}",
            null,
            [
                'name' => $validated['name'],
                'device' => $validated['device'],
                'type' => $validated['type'],
                'parent' => $validated['parent'] ?? null,
                'network' => $validated['network'] ?? null,
                'vlan' => $validated['vlan'] ?? null,
                'source_type' => $validated['source_type'] ?? null,
                'source' => $validated['source'] ?? null,
                'path' => $validated['path'] ?? null,
                'pool' => $validated['pool'] ?? null,
                'volume' => $validated['volume'] ?? null,
                'pci' => $validated['pci'] ?? null,
                'address' => $validated['address'] ?? null,
                'vendor_id' => $validated['vendor_id'] ?? null,
                'product_id' => $validated['product_id'] ?? null,
                'serial' => $validated['serial'] ?? null,
                'bus' => $validated['bus'] ?? null,
                'devnum' => $validated['devnum'] ?? null,
            ],
        );

        return response()->json(['message' => "Device {$validated['device']} attached."]);
    }

    public function updateDevice(Request $request, IncusService $incus, HostHealthService $health, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
            'device' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
            'key' => ['required', Rule::in(['vlan', 'mtu', 'size', 'readonly'])],
            'value' => ['nullable'],
        ]);

        $value = match (true) {
            $validated['key'] === 'readonly' => filter_var($validated['value'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            ($validated['value'] ?? null) === null => null,
            default => (string) $validated['value'],
        };

        try {
            $incus->updateDevice($validated['name'], $validated['device'], $validated['key'], $value);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $health->forget();
        $audit->log(
            'incus.device.set',
            "{$request->user()->name} changed {$validated['key']} on {$validated['device']} in {$validated['name']}",
            null,
            [
                'name' => $validated['name'],
                'device' => $validated['device'],
                'key' => $validated['key'],
                'value' => $value,
            ],
        );

        return response()->json(['message' => "Device {$validated['device']} updated."]);
    }

    public function removeDevice(Request $request, IncusService $incus, HostHealthService $health, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
            'device' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
        ]);

        try {
            $incus->removeDevice($validated['name'], $validated['device']);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $health->forget();
        $audit->log(
            'incus.device.remove',
            "{$request->user()->name} removed device {$validated['device']} from {$validated['name']}",
            null,
            ['name' => $validated['name'], 'device' => $validated['device']],
        );

        return response()->json(['message' => "Device {$validated['device']} removed."]);
    }

    public function overrideDevice(Request $request, IncusService $incus, HostHealthService $health, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
            'device' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
        ]);

        try {
            $incus->overrideDevice($validated['name'], $validated['device']);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $health->forget();
        $audit->log(
            'incus.device.override',
            "{$request->user()->name} copied profile device {$validated['device']} locally on {$validated['name']}",
            null,
            ['name' => $validated['name'], 'device' => $validated['device']],
        );

        return response()->json(['message' => "Device {$validated['device']} is now locally editable."]);
    }

    public function action(Request $request, IncusService $incus, HostHealthService $health, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['start', 'stop', 'restart', 'freeze', 'unfreeze', 'force-stop'])],
            'name' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
        ]);

        try {
            $incus->action($validated['action'], $validated['name']);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $health->forget();
        $audit->log('incus.'.$validated['action'], "{$request->user()->name} ran incus {$validated['action']} on {$validated['name']}", null, $validated);

        return back()->with('success', "Incus {$validated['action']} completed for {$validated['name']}.");
    }

    public function logs(Request $request, IncusService $incus): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
        ]);

        try {
            $data = $incus->logs($validated['name']);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($data);
    }

    public function destroy(Request $request, IncusService $incus, HostHealthService $health, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/'],
        ]);

        try {
            $incus->delete($validated['name']);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $health->forget();
        $audit->log(
            'incus.delete',
            "{$request->user()->name} deleted Incus instance {$validated['name']}",
            null,
            ['name' => $validated['name']],
        );

        return back()->with('success', "Incus instance {$validated['name']} was deleted.");
    }
}
