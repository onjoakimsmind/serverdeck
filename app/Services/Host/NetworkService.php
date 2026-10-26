<?php

namespace App\Services\Host;

use App\Exceptions\HostCommandException;

class NetworkService
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly PrivilegedHost $host,
    ) {}

    /**
     * @return array{ipv4: array<int, array<string, mixed>>, ipv6: array<int, array<string, mixed>>}
     */
    public function routes(): array
    {
        return [
            'ipv4' => $this->decodeRoutes($this->runner->run(['/usr/sbin/ip', '-j', 'route', 'show'], 10)),
            'ipv6' => $this->decodeRoutes($this->runner->run(['/usr/sbin/ip', '-j', '-6', 'route', 'show'], 10)),
        ];
    }

    /**
     * @return array{nameservers: array<int, string>, search: array<int, string>, options: array<int, string>}
     */
    public function dns(): array
    {
        $contents = @file_get_contents('/etc/resolv.conf') ?: '';
        $nameservers = [];
        $search = [];
        $options = [];

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^nameserver\s+(\S+)/', $line, $matches)) {
                $nameservers[] = $matches[1];
            } elseif (preg_match('/^search\s+(.+)$/', $line, $matches)) {
                $search = preg_split('/\s+/', trim($matches[1])) ?: [];
            } elseif (preg_match('/^options\s+(.+)$/', $line, $matches)) {
                $options = preg_split('/\s+/', trim($matches[1])) ?: [];
            }
        }

        return ['nameservers' => $nameservers, 'search' => $search, 'options' => $options];
    }

    /**
     * @return array{ports: array<int, array<string, mixed>>, error: string|null}
     */
    public function listeningPorts(): array
    {
        $result = $this->runner->run(['/usr/bin/ss', '-H', '-tulpn'], 10);

        if (! $result['ok']) {
            return ['ports' => [], 'error' => trim($result['stderr']) ?: 'Could not read socket statistics.'];
        }

        $ports = [];

        foreach (preg_split('/\r?\n/', trim($result['stdout'])) ?: [] as $line) {
            $columns = preg_split('/\s+/', trim($line)) ?: [];

            if (count($columns) < 6) {
                continue;
            }

            [$protocol, $state, , , $local, $peer] = $columns;
            $port = (int) (substr($local, (int) strrpos($local, ':') + 1) ?: 0);
            $processes = [];

            if (isset($columns[6]) && preg_match_all('/"([^"]+)"/', $columns[6], $matches)) {
                $processes = array_values(array_unique($matches[1]));
            }

            $ports[] = [
                'protocol' => $protocol,
                'state' => $state,
                'local_address' => $local,
                'peer_address' => $peer,
                'port' => $port,
                'processes' => $processes,
            ];
        }

        usort($ports, fn (array $a, array $b) => $a['port'] <=> $b['port']);

        return ['ports' => $ports, 'error' => null];
    }

    /**
     * @return array{available: bool, error: string|null, backend: string|null, ruleset: string, tables: array<int, array<string, mixed>>}
     */
    public function firewall(): array
    {
        try {
            $data = $this->host->run('network.firewall', [], 20);
        } catch (HostCommandException $exception) {
            return ['available' => false, 'error' => $exception->getMessage(), 'backend' => null, 'ruleset' => '', 'tables' => []];
        }

        $backend = (string) ($data['backend'] ?? '');
        $ruleset = (string) ($data['ruleset'] ?? '');

        return [
            'available' => true,
            'error' => null,
            'backend' => $backend,
            'ruleset' => $ruleset,
            'tables' => $backend === 'nft' ? $this->parseNftRuleset($ruleset) : $this->parseIptablesSaveRuleset($ruleset),
        ];
    }

    /**
     * Structured view of the host's INPUT chain, the one place it is safe to
     * let an operator add or remove rules without touching the tables Docker
     * and Incus manage for themselves.
     *
     * @return array{available: bool, error: string|null, policy: array<string, string|null>, rules: array<int, array<string, mixed>>}
     */
    public function firewallRules(): array
    {
        try {
            $data = $this->host->run('network.firewall_rules', [], 20);
        } catch (HostCommandException $exception) {
            return ['available' => false, 'error' => $exception->getMessage(), 'policy' => [], 'rules' => []];
        }

        $rules = collect($data['rules'] ?? [])
            ->map(fn (array $rule) => [
                'line' => (int) ($rule['line'] ?? 0),
                'family' => (string) ($rule['family'] ?? 'ipv4'),
                'action' => (string) ($rule['action'] ?? 'ACCEPT'),
                'protocol' => (string) ($rule['protocol'] ?? 'all'),
                'port' => $rule['port'] ?? null,
                'source' => (string) ($rule['source'] ?? ''),
                'destination' => (string) ($rule['destination'] ?? ''),
                'interface_in' => $rule['interface_in'] ?? null,
                'interface_out' => $rule['interface_out'] ?? null,
                'comment' => $rule['comment'] ?? null,
                'packets' => (int) ($rule['packets'] ?? 0),
                'bytes' => (int) ($rule['bytes'] ?? 0),
            ])
            ->values()
            ->all();

        return [
            'available' => true,
            'error' => null,
            'policy' => (array) ($data['policy'] ?? []),
            'rules' => $rules,
        ];
    }

    public function addFirewallRule(
        string $family,
        string $protocol,
        string $action,
        ?string $port = null,
        ?string $source = null,
        ?string $comment = null,
    ): array {
        return $this->host->run('network.firewall_rule_add', [
            'family' => $family,
            'protocol' => $protocol,
            'action' => $action,
            'port' => $port,
            'source' => $source,
            'comment' => $comment,
        ], 20);
    }

    public function deleteFirewallRule(string $family, int $line): array
    {
        return $this->host->run('network.firewall_rule_delete', [
            'family' => $family,
            'line' => $line,
        ], 20);
    }

    public function ping(string $target, int $count = 4): array
    {
        return $this->host->run('network.ping', ['target' => $target, 'count' => $count], 20);
    }

    public function dig(string $target, string $record = 'A'): array
    {
        return $this->host->run('network.dig', ['target' => $target, 'record' => $record], 15);
    }

    public function portCheck(string $target, int $port): array
    {
        return $this->host->run('network.portcheck', ['target' => $target, 'port' => $port], 12);
    }

    /**
     * Groups `nft list ruleset` output into tables and chains so the UI can
     * render it as collapsible sections instead of one raw text block.
     *
     * @return array<int, array{family: string, name: string, notice: string|null, chains: array<int, array{name: string, header: string|null, rules: array<int, string>}>}>
     */
    private function parseNftRuleset(string $ruleset): array
    {
        $tables = [];
        $table = null;
        $chain = null;
        $depth = 0;
        $warnings = [];

        foreach (preg_split('/\r?\n/', $ruleset) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, '# Warning:')) {
                // nftables prints these to stderr, so the helper appends them
                // after all of stdout rather than next to the table they
                // describe. Collect them separately and match by content below.
                $warnings[] = trim(substr($trimmed, strlen('# Warning:')));

                continue;
            }

            if ($depth === 0 && preg_match('/^table\s+(\S+)\s+(\S+)\s*\{$/', $trimmed, $matches)) {
                $table = ['family' => $matches[1], 'name' => $matches[2], 'notice' => null, 'chains' => []];
                $depth = 1;

                continue;
            }

            if ($depth === 1 && $trimmed === '}') {
                if ($table !== null) {
                    $tables[] = $table;
                }
                $table = null;
                $depth = 0;

                continue;
            }

            if ($depth === 1 && preg_match('/^chain\s+(\S+)\s*\{$/', $trimmed, $matches)) {
                $chain = ['name' => $matches[1], 'header' => null, 'rules' => []];
                $depth = 2;

                continue;
            }

            if ($depth === 2 && $trimmed === '}') {
                if ($table !== null && $chain !== null) {
                    $table['chains'][] = $chain;
                }
                $chain = null;
                $depth = 1;

                continue;
            }

            if ($depth === 2 && $chain !== null) {
                if ($chain['header'] === null && str_starts_with($trimmed, 'type ')) {
                    $chain['header'] = $trimmed;
                } else {
                    $chain['rules'][] = $trimmed;
                }
            }
        }

        foreach ($tables as &$table) {
            foreach ($warnings as $warning) {
                if (str_contains($warning, "table {$table['family']} {$table['name']} ")) {
                    $table['notice'] = $warning;

                    break;
                }
            }
        }
        unset($table);

        return $tables;
    }

    /**
     * Fallback parser for the `iptables-save` / `ip6tables-save` text format,
     * used only when nftables itself is unavailable.
     *
     * @return array<int, array{family: string, name: string, notice: string|null, chains: array<int, array{name: string, header: string|null, rules: array<int, string>}>}>
     */
    private function parseIptablesSaveRuleset(string $ruleset): array
    {
        $tables = [];
        $family = 'ip';
        $table = null;
        $chains = [];

        foreach (preg_split('/\r?\n/', $ruleset) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if ($trimmed === '# ip6tables') {
                $family = 'ip6';

                continue;
            }

            if (str_starts_with($trimmed, '#')) {
                continue;
            }

            if (str_starts_with($trimmed, '*')) {
                $table = ['family' => $family, 'name' => substr($trimmed, 1), 'notice' => null, 'chains' => []];
                $chains = [];

                continue;
            }

            if ($trimmed === 'COMMIT') {
                if ($table !== null) {
                    $table['chains'] = array_values($chains);
                    $tables[] = $table;
                }
                $table = null;
                $chains = [];

                continue;
            }

            if ($table === null) {
                continue;
            }

            if (str_starts_with($trimmed, ':')) {
                $parts = preg_split('/\s+/', ltrim($trimmed, ':')) ?: [];
                $name = $parts[0] ?? 'UNKNOWN';
                $chains[$name] ??= ['name' => $name, 'header' => isset($parts[1]) ? "policy {$parts[1]}" : null, 'rules' => []];

                continue;
            }

            if (str_starts_with($trimmed, '-A ')) {
                $parts = preg_split('/\s+/', $trimmed, 3) ?: [];
                $name = $parts[1] ?? 'UNKNOWN';
                $chains[$name] ??= ['name' => $name, 'header' => null, 'rules' => []];
                $chains[$name]['rules'][] = $trimmed;
            }
        }

        return $tables;
    }

    /**
     * @param  array{ok: bool, stdout: string, stderr: string, exit_code: int|null}  $result
     * @return array<int, array<string, mixed>>
     */
    private function decodeRoutes(array $result): array
    {
        if (! $result['ok']) {
            return [];
        }

        $decoded = json_decode($result['stdout'], true);

        return is_array($decoded) ? $decoded : [];
    }
}
