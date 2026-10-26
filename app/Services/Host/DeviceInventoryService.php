<?php

namespace App\Services\Host;

use App\Exceptions\HostCommandException;
use Illuminate\Support\Facades\Cache;

class DeviceInventoryService
{
    private const CACHE_KEY = 'host.devices';

    private const CACHE_SECONDS = 60;

    public function __construct(private readonly PrivilegedHost $host) {}

    /**
     * Host devices that can be passed through to Docker, Incus containers and
     * Incus virtual machines, annotated with current usage.
     *
     * The cached payload contains arrays and scalars only: Laravel's cache
     * unserializes with object support disabled.
     *
     * @return array<string, mixed>
     */
    public function overview(bool $refresh = false): array
    {
        if ($refresh) {
            $this->forget();
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function (): array {
            try {
                $data = $this->host->run('device.inventory', [], 45);
            } catch (HostCommandException $exception) {
                return $this->empty($exception->getMessage());
            }

            return $this->normalize($data);
        });
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $pci = $this->list($data['pci'] ?? []);
        $usb = $this->list($data['usb'] ?? []);
        $unix = $this->list($data['unix'] ?? []);
        $block = $this->list($data['block'] ?? []);
        $gpus = $this->list($data['gpus'] ?? []);

        foreach ($pci as $index => $device) {
            $pci[$index]['used_by'] = $this->list($device['used_by'] ?? []);
            $pci[$index]['warnings'] = array_values(array_filter((array) ($device['warnings'] ?? []), 'is_string'));
            $pci[$index]['incus'] = $this->pciCapabilities($device);
            $pci[$index]['docker'] = ($device['kind'] ?? '') === 'gpu' ? ['device', 'gpu'] : ['device'];
        }

        foreach ($usb as $index => $device) {
            $usb[$index]['used_by'] = $this->list($device['used_by'] ?? []);
            $usb[$index]['warnings'] = array_values(array_filter((array) ($device['warnings'] ?? []), 'is_string'));
            $usb[$index]['incus'] = ['container' => ['usb'], 'virtual-machine' => ['usb']];
            $usb[$index]['docker'] = ['device'];
        }

        foreach ($unix as $index => $device) {
            $unix[$index]['used_by'] = $this->list($device['used_by'] ?? []);
            $unix[$index]['warnings'] = array_values(array_filter((array) ($device['warnings'] ?? []), 'is_string'));
            $type = ($device['type'] ?? 'char') === 'block' ? 'unix-block' : 'unix-char';
            $unix[$index]['incus'] = ['container' => [$type], 'virtual-machine' => []];
            $unix[$index]['docker'] = ['device'];
        }

        foreach ($block as $index => $device) {
            $block[$index]['used_by'] = $this->list($device['used_by'] ?? []);
            $block[$index]['warnings'] = array_values(array_filter((array) ($device['warnings'] ?? []), 'is_string'));
            $block[$index]['incus'] = ['container' => ['unix-block'], 'virtual-machine' => []];
            $block[$index]['docker'] = ['device'];
        }

        foreach ($gpus as $index => $device) {
            $gpus[$index]['used_by'] = $this->list($device['used_by'] ?? []);
            $gpus[$index]['warnings'] = array_values(array_filter((array) ($device['warnings'] ?? []), 'is_string'));
            $gpus[$index]['drm_nodes'] = array_values(array_filter((array) ($device['drm_nodes'] ?? []), 'is_string'));
            $gpus[$index]['nvidia'] = is_array($device['nvidia'] ?? null) ? $device['nvidia'] : null;
            $gpus[$index]['incus'] = ($device['iommu_group'] ?? '') !== ''
                ? ['container' => ['gpu'], 'virtual-machine' => ['gpu', 'pci']]
                : ['container' => ['gpu'], 'virtual-machine' => []];
            $gpus[$index]['docker'] = ['device', 'gpu'];
        }

        $iommu = [
            'enabled' => (bool) ($data['iommu']['enabled'] ?? false),
            'groups' => (int) ($data['iommu']['groups'] ?? 0),
        ];

        $nvidia = $this->list($data['nvidia']['devices'] ?? []);
        $nvidiaState = [
            'available' => (bool) ($data['nvidia']['available'] ?? false),
            'error' => isset($data['nvidia']['error']) ? (string) $data['nvidia']['error'] : null,
            'devices' => $nvidia,
        ];

        $docker = [
            'available' => (bool) ($data['docker']['available'] ?? false),
            'error' => isset($data['docker']['error']) ? (string) $data['docker']['error'] : null,
            'runtimes' => array_values(array_filter((array) ($data['docker']['runtimes'] ?? []), 'is_string')),
            'nvidia_runtime' => (bool) ($data['docker']['nvidia_runtime'] ?? false),
            'swarm' => (string) ($data['docker']['swarm'] ?? 'unknown'),
        ];

        $warnings = [];

        if (! $iommu['enabled']) {
            $warnings[] = 'IOMMU is not enabled. PCI and VM GPU pass-through are unavailable.';
        }

        if ($gpus !== [] && ! $nvidiaState['available']) {
            $warnings[] = 'NVIDIA telemetry is unavailable'
                .($nvidiaState['error'] ? ': '.rtrim($nvidiaState['error'], '.').'.' : '.')
                .' GPU workloads may not function until the host driver is repaired.';
        }

        if ($gpus !== [] && ! $docker['nvidia_runtime']) {
            $warnings[] = 'Docker GPU requests need the NVIDIA container runtime, which is not registered with this Docker engine.';
        }

        if ($docker['swarm'] === 'active') {
            $warnings[] = 'Docker Swarm is active. Swarm-managed containers cannot have devices attached; reconfigure the service or use a standalone container.';
        }

        return [
            'available' => true,
            'error' => null,
            'scanned_at' => isset($data['scanned_at']) ? (int) $data['scanned_at'] : null,
            'iommu' => $iommu,
            'nvidia' => $nvidiaState,
            'docker' => $docker,
            'pci' => $pci,
            'usb' => $usb,
            'unix' => $unix,
            'block' => $block,
            'gpus' => $gpus,
            'warnings' => $warnings,
            'counts' => [
                'pci' => count($pci),
                'usb' => count($usb),
                'unix' => count($unix),
                'block' => count($block),
                'gpus' => count($gpus),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $device
     * @return array{container: array<int, string>, virtual-machine: array<int, string>}
     */
    private function pciCapabilities(array $device): array
    {
        $hasIommu = (string) ($device['iommu_group'] ?? '') !== '';
        $isGpu = ($device['kind'] ?? '') === 'gpu';

        if ($isGpu) {
            return [
                'container' => ['gpu'],
                'virtual-machine' => $hasIommu ? ['gpu', 'pci'] : [],
            ];
        }

        return [
            'container' => [],
            'virtual-machine' => $hasIommu ? ['pci'] : [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(string $error): array
    {
        return [
            'available' => false,
            'error' => $error,
            'scanned_at' => null,
            'iommu' => ['enabled' => false, 'groups' => 0],
            'nvidia' => ['available' => false, 'error' => null, 'devices' => []],
            'docker' => [
                'available' => false,
                'error' => null,
                'runtimes' => [],
                'nvidia_runtime' => false,
                'swarm' => 'unknown',
            ],
            'pci' => [],
            'usb' => [],
            'unix' => [],
            'block' => [],
            'gpus' => [],
            'warnings' => [],
            'counts' => ['pci' => 0, 'usb' => 0, 'unix' => 0, 'block' => 0, 'gpus' => 0],
        ];
    }
}
