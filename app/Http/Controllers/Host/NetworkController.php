<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Host\DockerService;
use App\Services\Host\HostMetricsService;
use App\Services\Host\IncusService;
use App\Services\Host\NetworkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NetworkController extends Controller
{
    private const TARGET_RULES = ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9]([A-Za-z0-9:._-]{0,251}[A-Za-z0-9])?$/'];

    public function index(
        HostMetricsService $metrics,
        NetworkService $network,
        IncusService $incus,
        DockerService $docker,
    ): Response {
        return Inertia::render('Network/Index', [
            'network' => $metrics->network(),
            'routes' => $network->routes(),
            'dns' => $network->dns(),
            'listening' => $network->listeningPorts(),
            'firewall' => $network->firewall(),
            'firewallRules' => $network->firewallRules(),
            'incusNetworks' => $incus->networks(),
            'dockerNetworks' => $docker->networks(),
        ]);
    }

    public function firewallRuleStore(Request $request, NetworkService $network, AuditLogger $audit): RedirectResponse
    {
        $validated = $request->validate([
            'family' => ['required', Rule::in(['ipv4', 'ipv6'])],
            'protocol' => ['required', Rule::in(['tcp', 'udp', 'icmp', 'all'])],
            'action' => ['required', Rule::in(['accept', 'drop', 'reject'])],
            'port' => ['nullable', 'string', 'max:11', 'regex:/^\d{1,5}(:\d{1,5})?$/'],
            'source' => ['nullable', 'string', 'max:64', 'regex:/^[0-9a-fA-F:.\/]+$/'],
            'comment' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9 ._:\/-]*$/'],
        ]);

        if (! empty($validated['port']) && ! in_array($validated['protocol'], ['tcp', 'udp'], true)) {
            throw ValidationException::withMessages([
                'port' => 'A port requires the TCP or UDP protocol.',
            ]);
        }

        try {
            $network->addFirewallRule(
                $validated['family'],
                $validated['protocol'],
                $validated['action'],
                $validated['port'] ?? null,
                $validated['source'] ?? null,
                $validated['comment'] ?? null,
            );
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->log(
            'network.firewall_rule_add',
            sprintf(
                '%s added a %s %s rule for %s%s on %s',
                $request->user()->name,
                strtoupper($validated['family'] === 'ipv6' ? 'IPv6' : 'IPv4'),
                strtoupper($validated['action']),
                $validated['protocol'],
                ! empty($validated['port']) ? " port {$validated['port']}" : '',
                gethostname(),
            ),
            null,
            $validated,
        );

        return back()->with('success', 'Firewall rule added to the INPUT chain.');
    }

    public function firewallRuleDestroy(Request $request, string $family, int $line, NetworkService $network, AuditLogger $audit): RedirectResponse
    {
        try {
            $network->deleteFirewallRule($family, $line);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->log(
            'network.firewall_rule_delete',
            "{$request->user()->name} removed {$family} INPUT rule #{$line} on ".gethostname(),
            null,
            ['family' => $family, 'line' => $line],
        );

        return back()->with('success', 'Firewall rule removed.');
    }

    public function ping(Request $request, NetworkService $network, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'target' => self::TARGET_RULES,
            'count' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            $result = $network->ping($validated['target'], (int) ($validated['count'] ?? 4));
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $audit->log(
            'network.ping',
            "{$request->user()->name} pinged {$validated['target']} from ".gethostname(),
            null,
            ['target' => $validated['target'], 'exit_code' => $result['exit_code'] ?? null],
        );

        return response()->json($result);
    }

    public function dig(Request $request, NetworkService $network, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'target' => self::TARGET_RULES,
            'record' => ['nullable', 'string', Rule::in(['A', 'AAAA', 'MX', 'TXT', 'NS', 'CNAME', 'SOA', 'PTR'])],
        ]);

        try {
            $result = $network->dig($validated['target'], $validated['record'] ?? 'A');
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $record = $validated['record'] ?? 'A';

        $audit->log(
            'network.dig',
            "{$request->user()->name} looked up {$record} for {$validated['target']} on ".gethostname(),
            null,
            ['target' => $validated['target'], 'record' => $record, 'exit_code' => $result['exit_code'] ?? null],
        );

        return response()->json($result);
    }

    public function portCheck(Request $request, NetworkService $network, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validate([
            'target' => self::TARGET_RULES,
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
        ]);

        try {
            $result = $network->portCheck($validated['target'], (int) $validated['port']);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $audit->log(
            'network.portcheck',
            "{$request->user()->name} checked {$validated['target']}:{$validated['port']} from ".gethostname(),
            null,
            ['target' => $validated['target'], 'port' => $validated['port'], 'open' => $result['open'] ?? null],
        );

        return response()->json($result);
    }
}
