<?php

namespace App\Services\Host;

use Illuminate\Support\Facades\Cache;

class HostMetricsService
{
    public function __construct(private readonly CommandRunner $runner) {}

    /**
     * A complete, real sample of the machine ServerDeck is running on.
     */
    public function snapshot(): array
    {
        $cpu = $this->cpu();
        $memory = $this->memory();
        $network = $this->network();
        $disks = $this->disks();
        $gpu = $this->gpu();

        return [
            'host' => $this->identity(),
            'cpu' => $cpu,
            'memory' => $memory,
            'network' => $network,
            'disks' => $disks,
            'gpu' => $gpu,
            'processes' => $this->processCount(),
            'temperature' => $this->temperature(),
            'sampled_at' => now()->toIso8601String(),
        ];
    }

    public function identity(): array
    {
        return Cache::remember('host.identity', 120, function () {
            $cpuInfo = @file_get_contents('/proc/cpuinfo') ?: '';
            $osRelease = $this->osRelease();
            $threads = preg_match_all('/^processor\s*:/m', $cpuInfo);
            $coresPerSocket = preg_match('/^cpu cores\s*:\s*(\d+)/m', $cpuInfo, $cores) ? (int) $cores[1] : null;
            $sockets = preg_match('/^physical id\s*:\s*(\d+)/m', $cpuInfo, $socket) ? 1 : 1;
            $sockets = preg_match_all('/^physical id\s*:\s*(\d+)/m', $cpuInfo, $socketIds) ? count(array_unique($socketIds[1])) : 1;
            $model = preg_match('/^model name\s*:\s*(.+)$/m', $cpuInfo, $matches) ? trim($matches[1]) : php_uname('m');
            $virtualization = str_contains($cpuInfo, ' vmx ') || str_contains($cpuInfo, ' svm ') ? 'VT-x / AMD-V' : null;
            $uptime = $this->uptimeSeconds();

            return [
                'hostname' => gethostname() ?: 'localhost',
                'os' => $osRelease['PRETTY_NAME'] ?? php_uname('s'),
                'os_id' => $osRelease['ID'] ?? null,
                'os_version' => $osRelease['VERSION_ID'] ?? null,
                'kernel' => php_uname('r'),
                'arch' => php_uname('m'),
                'cpu_model' => $model,
                'cpu_threads' => max(1, $threads),
                'cpu_cores' => $coresPerSocket ? $coresPerSocket * $sockets : max(1, $threads),
                'sockets' => $sockets,
                'virtualization' => $virtualization,
                'uptime_seconds' => $uptime,
                'booted_at' => now()->subSeconds($uptime)->toIso8601String(),
                'timezone' => date_default_timezone_get(),
            ];
        });
    }

    public function cpu(): array
    {
        $sample = $this->cpuSample();
        $previous = Cache::get('host.cpu.sample');

        if (! is_array($previous) || ! isset($previous['at']) || (time() - (int) $previous['at']) > 300) {
            usleep(140_000);
            $second = $this->readCpu();

            [$usage, $perCore] = $this->cpuDelta($sample, $second);
            Cache::put('host.cpu.sample', ['at' => time()] + $second, 600);

            return [
                'usage' => $usage,
                'per_core' => $perCore,
                'load_1' => $this->load()[0],
                'load_5' => $this->load()[1],
                'load_15' => $this->load()[2],
            ];
        }

        [$usage, $perCore] = $this->cpuDelta($previous, $sample);
        Cache::put('host.cpu.sample', ['at' => time()] + $sample, 600);

        return [
            'usage' => $usage,
            'per_core' => $perCore,
            'load_1' => $this->load()[0],
            'load_5' => $this->load()[1],
            'load_15' => $this->load()[2],
        ];
    }

    public function memory(): array
    {
        $values = [];
        foreach (@file('/proc/meminfo') ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches)) {
                $values[$matches[1]] = (int) $matches[2] * 1024;
            }
        }

        $total = $values['MemTotal'] ?? 0;
        $available = $values['MemAvailable'] ?? ($values['MemFree'] ?? 0);
        $used = max(0, $total - $available);
        $swapTotal = $values['SwapTotal'] ?? 0;
        $swapFree = $values['SwapFree'] ?? 0;
        $swapUsed = max(0, $swapTotal - $swapFree);

        return [
            'total' => $total,
            'used' => $used,
            'available' => $available,
            'free' => $values['MemFree'] ?? 0,
            'buffers' => $values['Buffers'] ?? 0,
            'cached' => ($values['Cached'] ?? 0) + ($values['SReclaimable'] ?? 0),
            'shared' => $values['Shmem'] ?? 0,
            'percent' => $total > 0 ? round($used / $total * 100, 1) : 0,
            'swap_total' => $swapTotal,
            'swap_used' => $swapUsed,
            'swap_percent' => $swapTotal > 0 ? round($swapUsed / $swapTotal * 100, 1) : 0,
        ];
    }

    public function network(): array
    {
        $current = $this->readNetwork();
        $previous = Cache::get('host.network.sample');
        $elapsed = is_array($previous) && isset($previous['at']) ? max(0.2, microtime(true) - (float) $previous['at']) : null;

        $interfaces = [];
        $rxRate = 0;
        $txRate = 0;

        foreach ($current as $name => $counters) {
            $old = $previous[$name] ?? null;
            $rx = $elapsed && is_array($old) ? max(0, $counters['rx_bytes'] - (int) $old['rx_bytes']) / $elapsed : 0;
            $tx = $elapsed && is_array($old) ? max(0, $counters['tx_bytes'] - (int) $old['tx_bytes']) / $elapsed : 0;

            if ($name !== 'lo') {
                $rxRate += $rx;
                $txRate += $tx;
            }

            $interfaces[] = [
                'name' => $name,
                'state' => @file_get_contents("/sys/class/net/{$name}/operstate") ?: 'unknown',
                'mac' => @file_get_contents("/sys/class/net/{$name}/address") ?: null,
                'mtu' => (int) (@file_get_contents("/sys/class/net/{$name}/mtu") ?: 0),
                'speed' => (int) (@file_get_contents("/sys/class/net/{$name}/speed") ?: 0),
                'rx_bytes' => $counters['rx_bytes'],
                'tx_bytes' => $counters['tx_bytes'],
                'rx_rate' => round($rx, 1),
                'tx_rate' => round($tx, 1),
                'rx_packets' => $counters['rx_packets'],
                'tx_packets' => $counters['tx_packets'],
            ];
        }

        Cache::put('host.network.sample', ['at' => microtime(true)] + $current, 600);
        usort($interfaces, fn ($a, $b) => $a['name'] === 'lo' ? 1 : ($b['name'] === 'lo' ? -1 : strcmp($a['name'], $b['name'])));

        return [
            'interfaces' => $interfaces,
            'rx_rate' => round($rxRate, 1),
            'tx_rate' => round($txRate, 1),
            'rx_total' => array_sum(array_column($interfaces, 'rx_bytes')),
            'tx_total' => array_sum(array_column($interfaces, 'tx_bytes')),
        ];
    }

    public function disks(): array
    {
        $result = $this->runner->run(['df', '-PT', '-B1', '-l'], 10);
        $disks = [];

        if (! $result['ok']) {
            return ['filesystems' => [], 'root' => null, 'error' => trim($result['stderr'])];
        }

        $skipped = ['tmpfs', 'devtmpfs', 'squashfs', 'overlay', 'proc', 'sysfs', 'cgroup', 'cgroup2', 'efivarfs', 'ramfs', 'autofs', 'fuse.gvfsd-fuse'];

        foreach (array_slice(preg_split('/\r?\n/', $result['stdout']) ?: [], 1) as $line) {
            $parts = preg_split('/\s+/', trim($line));

            if (count($parts) < 7) {
                continue;
            }

            [$source, $fsType, $size, $used, $available, $capacity, $mount] = $parts;

            if (in_array($fsType, $skipped, true) || $size === '0') {
                continue;
            }

            $disks[] = [
                'source' => $source,
                'type' => $fsType,
                'size' => (int) $size,
                'used' => (int) $used,
                'available' => (int) $available,
                'percent' => (float) rtrim($capacity, '%'),
                'mount' => $mount,
                'root' => $mount === '/',
            ];
        }

        usort($disks, fn ($a, $b) => $a['mount'] === '/' ? -1 : ($b['mount'] === '/' ? 1 : strcmp($a['mount'], $b['mount'])));

        return [
            'filesystems' => $disks,
            'root' => collect($disks)->firstWhere('root', true),
            'error' => null,
        ];
    }

    public function blockDevices(): array
    {
        $result = $this->runner->run([
            '/usr/bin/lsblk',
            '-J',
            '-b',
            '-o',
            'NAME,PATH,SIZE,TYPE,FSTYPE,MOUNTPOINTS,MODEL,SERIAL,ROTA',
        ], 10);

        if (! $result['ok']) {
            return ['devices' => [], 'available' => [], 'error' => trim($result['stderr'])];
        }

        $tree = json_decode($result['stdout'], true);

        if (! is_array($tree)) {
            return ['devices' => [], 'available' => [], 'error' => 'Could not read lsblk output.'];
        }

        $devices = [];
        $walk = function (array $node, bool $parentUsed = false) use (&$walk, &$devices) {
            $mounts = array_values(array_filter($node['mountpoints'] ?? []));
            $fstype = $node['fstype'] ?? null;
            $used = $parentUsed || $mounts !== [] || $fstype === 'zfs_member';
            $children = $node['children'] ?? [];

            if (! in_array($node['type'] ?? '', ['loop', 'rom'], true)) {
                $devices[] = [
                    'name' => $node['name'] ?? '',
                    'path' => $node['path'] ?? '',
                    'size' => (int) ($node['size'] ?? 0),
                    'type' => $node['type'] ?? '',
                    'fstype' => $fstype,
                    'mounts' => $mounts,
                    'model' => trim((string) ($node['model'] ?? '')),
                    'serial' => trim((string) ($node['serial'] ?? '')),
                    'rotational' => (bool) ($node['rota'] ?? false),
                    'used' => $used,
                    'children' => count($children),
                ];
            }

            foreach ($children as $child) {
                $walk($child, $used);
            }
        };

        foreach ($tree['blockdevices'] ?? [] as $node) {
            $walk($node);
        }

        $available = array_values(array_filter($devices, fn ($device) => $device['type'] === 'disk' && ! $device['used'] && $device['size'] > 0));

        return ['devices' => $devices, 'available' => $available, 'error' => null];
    }

    public function gpu(): array
    {
        return Cache::remember('host.gpu', 10, function () {
            if (! is_executable('/usr/bin/nvidia-smi')) {
                return [
                    'available' => false,
                    'devices' => $this->lspciGpus(),
                    'error' => 'nvidia-smi is not installed.',
                ];
            }

            $result = $this->runner->run([
                '/usr/bin/nvidia-smi',
                '--query-gpu=index,name,uuid,driver_version,memory.total,memory.used,utilization.gpu,temperature.gpu,power.draw',
                '--format=csv,noheader,nounits',
            ], 10);

            if (! $result['ok']) {
                return [
                    'available' => false,
                    'devices' => $this->lspciGpus(),
                    'error' => trim($result['stderr']) ?: 'The NVIDIA driver could not query any devices.',
                ];
            }

            $devices = [];
            foreach (preg_split('/\r?\n/', trim($result['stdout'])) ?: [] as $line) {
                $parts = array_map('trim', explode(',', $line));

                if (count($parts) < 9) {
                    continue;
                }

                $devices[] = [
                    'index' => (int) $parts[0],
                    'name' => $parts[1],
                    'uuid' => $parts[2],
                    'driver' => $parts[3],
                    'memory_total' => $this->number($parts[4]) * 1024 * 1024,
                    'memory_used' => $this->number($parts[5]) * 1024 * 1024,
                    'utilization' => $this->number($parts[6]),
                    'temperature' => $this->number($parts[7]),
                    'power' => $this->number($parts[8]),
                ];
            }

            return [
                'available' => $devices !== [],
                'devices' => $devices ?: $this->lspciGpus(),
                'error' => $devices === [] ? 'No CUDA devices were reported by the driver.' : null,
            ];
        });
    }

    public function uptimeSeconds(): int
    {
        $uptime = @file_get_contents('/proc/uptime');

        return $uptime ? (int) floor((float) explode(' ', $uptime)[0]) : 0;
    }

    public function processCount(): int
    {
        return count(glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: []);
    }

    private function cpuSample(): array
    {
        return $this->readCpu();
    }

    private function readCpu(): array
    {
        $cores = [];
        foreach (@file('/proc/stat') ?: [] as $line) {
            if (! preg_match('/^cpu(\d*)\s+(.+)$/', trim($line), $matches)) {
                continue;
            }

            $values = array_map('intval', preg_split('/\s+/', trim($matches[2])) ?: []);
            $idle = ($values[3] ?? 0) + ($values[4] ?? 0);
            $total = array_sum($values);
            $cores[$matches[1] === '' ? 'all' : $matches[1]] = ['total' => $total, 'idle' => $idle];
        }

        return $cores;
    }

    private function cpuDelta(array $before, array $after): array
    {
        $usageFor = function (array $old, array $new): ?float {
            if (! $old || ! $new) {
                return null;
            }

            $total = $new['total'] - $old['total'];
            $idle = $new['idle'] - $old['idle'];

            if ($total <= 0) {
                return 0.0;
            }

            return round(max(0, min(100, (1 - $idle / $total) * 100)), 1);
        };

        $usage = $usageFor($before['all'] ?? [], $after['all'] ?? []) ?? 0.0;
        $perCore = [];

        $coreKeys = array_filter(array_keys($after), fn ($key) => $key !== 'all');
        usort($coreKeys, fn ($a, $b) => (int) $a <=> (int) $b);

        foreach ($coreKeys as $key) {
            $perCore[] = [
                'core' => (int) $key,
                'usage' => $usageFor($before[$key] ?? [], $after[$key] ?? []) ?? 0.0,
            ];
        }

        return [$usage, $perCore];
    }

    private function readNetwork(): array
    {
        $interfaces = [];

        foreach (@file('/proc/net/dev') ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $counters] = explode(':', $line, 2);
            $name = trim($name);
            $values = preg_split('/\s+/', trim($counters)) ?: [];

            if (count($values) < 16) {
                continue;
            }

            $interfaces[$name] = [
                'rx_bytes' => (int) $values[0],
                'rx_packets' => (int) $values[1],
                'tx_bytes' => (int) $values[8],
                'tx_packets' => (int) $values[9],
            ];
        }

        return $interfaces;
    }

    private function load(): array
    {
        $load = @file_get_contents('/proc/loadavg') ?: '0 0 0';
        $parts = explode(' ', $load);

        return [(float) ($parts[0] ?? 0), (float) ($parts[1] ?? 0), (float) ($parts[2] ?? 0)];
    }

    private function temperature(): ?array
    {
        $zones = glob('/sys/class/thermal/thermal_zone*/temp') ?: [];

        foreach ($zones as $zone) {
            $raw = @file_get_contents($zone);

            if ($raw === false || ! is_numeric(trim($raw))) {
                continue;
            }

            $type = @file_get_contents(dirname($zone).'/type') ?: 'thermal';

            return ['label' => trim($type), 'celsius' => round((int) $raw / 1000, 1)];
        }

        return null;
    }

    private function lspciGpus(): array
    {
        if (! is_executable('/usr/bin/lspci')) {
            return [];
        }

        $result = $this->runner->run(['/usr/bin/lspci'], 10);

        if (! $result['ok']) {
            return [];
        }

        $devices = [];
        foreach (preg_split('/\r?\n/', $result['stdout']) ?: [] as $line) {
            if (! preg_match('/VGA compatible controller|3D controller|Display controller/i', $line)) {
                continue;
            }

            [$slot, $name] = array_pad(explode(' ', $line, 2), 2, '');
            $devices[] = [
                'index' => null,
                'name' => trim($name),
                'uuid' => $slot,
                'driver' => null,
                'memory_total' => null,
                'memory_used' => null,
                'utilization' => null,
                'temperature' => null,
                'power' => null,
            ];
        }

        return $devices;
    }

    private function number(string $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function osRelease(): array
    {
        $values = [];

        foreach (@file('/etc/os-release') ?: [] as $line) {
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $values[$key] = trim($value, " \t\n\r\0\x0B\"");
            }
        }

        return $values;
    }
}
