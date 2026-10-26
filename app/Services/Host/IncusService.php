<?php

namespace App\Services\Host;

use App\Exceptions\HostCommandException;
use Illuminate\Support\Facades\Cache;

class IncusService
{
    public function __construct(private readonly PrivilegedHost $host) {}

    public function overview(): array
    {
        try {
            $data = $this->host->run('incus.list', [], 30);
        } catch (HostCommandException $exception) {
            return [
                'available' => false,
                'error' => $exception->getMessage(),
                'instances' => [],
                'counts' => ['all' => 0, 'running' => 0, 'stopped' => 0],
            ];
        }

        $instances = collect($data['instances'] ?? [])
            ->map(function (array $instance) {
                $state = $instance['state'] ?? [];
                $network = $state['network'] ?? [];
                $addresses = [];

                foreach ($network as $interface) {
                    foreach ($interface['addresses'] ?? [] as $address) {
                        if (($address['family'] ?? null) === 'inet' && ($address['scope'] ?? 'global') === 'global') {
                            $addresses[] = $address['address'];
                        }
                    }
                }

                $memory = $state['memory'] ?? [];
                $disk = $state['disk']['root'] ?? [];

                return [
                    'name' => (string) ($instance['name'] ?? 'unknown'),
                    'type' => (string) ($instance['type'] ?? 'container'),
                    'status' => strtolower((string) ($instance['status'] ?? 'unknown')),
                    'architecture' => (string) ($instance['architecture'] ?? ''),
                    'description' => (string) ($instance['description'] ?? ''),
                    'project' => (string) ($instance['project'] ?? 'default'),
                    'profiles' => $instance['profiles'] ?? [],
                    'image' => (string) ($instance['config']['image.description'] ?? $instance['config']['image.os'] ?? ''),
                    'created_at' => (string) ($instance['created_at'] ?? ''),
                    'started_at' => (string) ($state['started_at'] ?? ''),
                    'ipv4' => $addresses,
                    'processes' => (int) ($state['processes'] ?? 0),
                    'memory_used' => (int) ($memory['usage'] ?? 0),
                    'memory_total' => (int) ($memory['total'] ?? 0),
                    'memory_percent' => (int) ($memory['total'] ?? 0) > 0
                        ? round((int) $memory['usage'] / (int) $memory['total'] * 100, 1)
                        : 0,
                    'disk_used' => (int) ($disk['usage'] ?? 0),
                    'disk_total' => (int) ($disk['total'] ?? 0),
                    'disk_percent' => (int) ($disk['total'] ?? 0) > 0
                        ? round((int) $disk['usage'] / (int) $disk['total'] * 100, 1)
                        : 0,
                ];
            })
            ->sortBy(fn ($instance) => [$instance['status'] === 'running' ? 0 : 1, $instance['name']])
            ->values();

        return [
            'available' => true,
            'error' => null,
            'instances' => $instances,
            'counts' => [
                'all' => $instances->count(),
                'running' => $instances->where('status', 'running')->count(),
                'stopped' => $instances->whereIn('status', ['stopped', 'shutdown'])->count(),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function images(): array
    {
        return Cache::remember('incus.images', 120, function () {
            try {
                $data = $this->host->run('incus.images', [], 30);
            } catch (HostCommandException) {
                return [];
            }

            return collect($data['images'] ?? [])
                ->filter(fn (array $image) => preg_match('/^[a-f0-9]{64}$/', (string) ($image['fingerprint'] ?? '')) === 1)
                ->map(fn (array $image) => [
                    'fingerprint' => (string) $image['fingerprint'],
                    'type' => (string) ($image['type'] ?? 'container'),
                    'os' => (string) ($image['os'] ?? ''),
                    'release' => (string) ($image['release'] ?? ''),
                    'variant' => (string) ($image['variant'] ?? ''),
                    'description' => (string) ($image['description'] ?? ''),
                    'architecture' => (string) ($image['architecture'] ?? ''),
                    'size' => (int) ($image['size'] ?? 0),
                    'label' => trim(sprintf(
                        '%s %s%s',
                        $image['os'] ?? 'Image',
                        $image['release'] ?? '',
                        ! empty($image['variant']) ? ' · '.$image['variant'] : '',
                    )),
                ])
                ->sortBy(fn (array $image) => [$image['type'], $image['os'], $image['release'], $image['variant']])
                ->values()
                ->all();
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function networks(): array
    {
        try {
            $data = $this->host->run('incus.networks', [], 25);
        } catch (HostCommandException) {
            return [];
        }

        return collect($data['networks'] ?? [])
            ->map(fn (array $network) => [
                'name' => (string) ($network['name'] ?? ''),
                'type' => (string) ($network['type'] ?? ''),
                'managed' => (bool) ($network['managed'] ?? false),
                'description' => (string) ($network['description'] ?? ''),
                'used_by' => count($network['used_by'] ?? []),
                'instances' => collect($network['used_by'] ?? [])
                    ->map(fn (string $url) => str_contains($url, '/instances/') ? last(explode('/', $url)) : null)
                    ->filter()
                    ->values()
                    ->all(),
                'ipv4_address' => (string) ($network['config']['ipv4.address'] ?? ''),
                'ipv4_nat' => ($network['config']['ipv4.nat'] ?? null) === 'true',
                'ipv6_address' => (string) ($network['config']['ipv6.address'] ?? ''),
                'dns_domain' => (string) ($network['config']['dns.domain'] ?? ''),
            ])
            ->sortBy([
                fn ($a, $b) => ($b['managed'] ? 1 : 0) <=> ($a['managed'] ? 1 : 0),
                fn ($a, $b) => strcasecmp($a['name'], $b['name']),
            ])
            ->values()
            ->all();
    }

    /**
     * Catalog of images from the remote `images:` server, grouped by alias
     * so container and virtual-machine variants of the same release are
     * presented as a single pickable entry.
     *
     * @return array<int, array<string, mixed>>
     */
    public function remoteImages(): array
    {
        return Cache::remember('incus.remote_images', 3600, function () {
            try {
                $data = $this->host->run('incus.remote_images', [], 35);
            } catch (HostCommandException) {
                return [];
            }

            $catalog = [];

            foreach ($data['images'] ?? [] as $image) {
                $type = (string) ($image['type'] ?? '');

                if (! in_array($type, ['container', 'virtual-machine'], true)) {
                    continue;
                }

                $aliases = collect($image['aliases'] ?? [])->filter()->values();

                if ($aliases->isEmpty()) {
                    continue;
                }

                $alias = $aliases->sort(fn ($a, $b) => strlen($a) <=> strlen($b))->first();

                $catalog[$alias] ??= [
                    'alias' => $alias,
                    'ref' => 'images:'.$alias,
                    'os' => (string) ($image['os'] ?? ''),
                    'release' => (string) ($image['release'] ?? ''),
                    'variant' => (string) ($image['variant'] ?? ''),
                    'description' => (string) ($image['description'] ?? ''),
                    'architecture' => (string) ($image['architecture'] ?? ''),
                    'label' => trim(sprintf(
                        '%s %s%s',
                        $image['os'] ?? 'Image',
                        $image['release'] ?? '',
                        ! empty($image['variant']) ? ' · '.$image['variant'] : '',
                    )),
                    'container' => null,
                    'virtual_machine' => null,
                ];

                $slot = $type === 'virtual-machine' ? 'virtual_machine' : 'container';
                $catalog[$alias][$slot] = [
                    'fingerprint' => (string) ($image['fingerprint'] ?? ''),
                    'size' => (int) ($image['size'] ?? 0),
                ];
            }

            return collect($catalog)
                ->sortBy(fn (array $entry) => [strtolower($entry['os']), strtolower($entry['release']), strtolower($entry['variant'])])
                ->values()
                ->all();
        });
    }

    public function create(string $name, string $type, string $image, bool $start): array
    {
        return $this->host->run('incus.create', [
            'name' => $name,
            'type' => $type,
            'image' => $image,
            'start' => $start,
        ], 620);
    }

    public function action(string $action, string $name): array
    {
        return $this->host->run('incus.action', ['action' => $action, 'name' => $name], 120);
    }

    public function logs(string $name): array
    {
        return $this->host->run('incus.logs', ['name' => $name], 30);
    }

    public function delete(string $name): array
    {
        return $this->host->run('incus.delete', ['name' => $name], 130);
    }

    /**
     * Full local configuration for one instance, plus the networks, bridges,
     * storage pools and custom volumes the editor can attach.
     *
     * @return array<string, mixed>
     */
    public function detail(string $name): array
    {
        $detail = $this->host->run('incus.detail', ['name' => $name], 40);

        $detail['bridges'] = collect($detail['bridges'] ?? [])
            ->reject(fn (array $bridge) => str_starts_with($bridge['name'], 'docker')
                || str_starts_with($bridge['name'], 'veth')
                || $bridge['name'] === 'lo'
                || preg_match('/^br-[0-9a-f]{6,}$/', $bridge['name']) === 1)
            ->values()
            ->all();

        $detail['storage_volumes'] = collect($detail['storage_volumes'] ?? [])
            ->map(fn (array $volume) => [
                ...$volume,
                'available' => (int) ($volume['used_by'] ?? 0) === 0,
            ])
            ->values()
            ->all();

        return $detail;
    }

    /**
     * @param  array<string, string>  $config
     */
    public function updateConfig(string $name, array $config, string $description): array
    {
        return $this->host->run('incus.config_update', [
            'name' => $name,
            'config' => $config,
            'description' => $description,
        ], 60);
    }

    /**
     * @param  array<string, mixed>  $device
     */
    public function addDevice(string $name, array $device): array
    {
        return $this->host->run('incus.device_add', [
            'name' => $name,
            'device' => $device['device'],
            'type' => $device['type'],
            'network' => $device['network'] ?? null,
            'parent' => $device['parent'] ?? null,
            'vlan' => $device['vlan'] ?? null,
            'mtu' => $device['mtu'] ?? null,
            'source_type' => $device['source_type'] ?? null,
            'source' => $device['source'] ?? null,
            'path' => $device['path'] ?? null,
            'pool' => $device['pool'] ?? null,
            'volume' => $device['volume'] ?? null,
            'readonly' => (bool) ($device['readonly'] ?? false),
            'gputype' => $device['gputype'] ?? null,
            'pci' => $device['pci'] ?? null,
            'address' => $device['address'] ?? null,
            'vendor_id' => $device['vendor_id'] ?? null,
            'product_id' => $device['product_id'] ?? null,
            'serial' => $device['serial'] ?? null,
            'bus' => $device['bus'] ?? null,
            'devnum' => $device['devnum'] ?? null,
        ], 60);
    }

    public function updateDevice(string $name, string $device, string $key, ?string $value): array
    {
        return $this->host->run('incus.device_set', [
            'name' => $name,
            'device' => $device,
            'key' => $key,
            'value' => $value,
        ], 60);
    }

    public function removeDevice(string $name, string $device): array
    {
        return $this->host->run('incus.device_remove', [
            'name' => $name,
            'device' => $device,
        ], 60);
    }

    public function overrideDevice(string $name, string $device): array
    {
        return $this->host->run('incus.device_override', [
            'name' => $name,
            'device' => $device,
        ], 60);
    }
}
