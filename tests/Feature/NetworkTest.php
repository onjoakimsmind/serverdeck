<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Host\DockerService;
use App\Services\Host\HostMetricsService;
use App\Services\Host\IncusService;
use App\Services\Host\NetworkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithHost;
use Tests\TestCase;

class NetworkTest extends TestCase
{
    use InteractsWithHost, RefreshDatabase;

    private function fakeNetworkOverview(): void
    {
        $this->fakeHealth();

        $this->mock(HostMetricsService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('identity')->andReturn($this->hostIdentity());
            $mock->shouldReceive('network')->once()->andReturn([
                'interfaces' => [],
                'rx_rate' => 0,
                'tx_rate' => 0,
                'rx_total' => 0,
                'tx_total' => 0,
            ]);
        });

        $this->mock(NetworkService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('routes')->once()->andReturn([
                'ipv4' => [['dst' => 'default', 'gateway' => '192.168.2.1', 'dev' => 'br0']],
                'ipv6' => [],
            ]);
            $mock->shouldReceive('dns')->once()->andReturn([
                'nameservers' => ['127.0.0.53'],
                'search' => [],
                'options' => [],
            ]);
            $mock->shouldReceive('listeningPorts')->once()->andReturn([
                'ports' => [['protocol' => 'tcp', 'state' => 'LISTEN', 'local_address' => '0.0.0.0:8090', 'peer_address' => '0.0.0.0:*', 'port' => 8090, 'processes' => ['nginx']]],
                'error' => null,
            ]);
            $mock->shouldReceive('firewall')->once()->andReturn([
                'available' => true,
                'error' => null,
                'backend' => 'nft',
                'ruleset' => "table inet filter {\n}",
                'tables' => [
                    ['family' => 'inet', 'name' => 'filter', 'notice' => null, 'chains' => []],
                ],
            ]);
            $mock->shouldReceive('firewallRules')->once()->andReturn([
                'available' => true,
                'error' => null,
                'policy' => ['ipv4' => 'ACCEPT', 'ipv6' => 'ACCEPT'],
                'rules' => [
                    ['line' => 1, 'family' => 'ipv4', 'action' => 'ACCEPT', 'protocol' => 'tcp', 'port' => '8080', 'source' => '0.0.0.0/0', 'destination' => '0.0.0.0/0', 'interface_in' => null, 'interface_out' => null, 'comment' => 'AMP Management Instance', 'packets' => 10, 'bytes' => 100],
                ],
            ]);
        });

        $this->mock(IncusService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('networks')->once()->andReturn([
                ['name' => 'br0', 'type' => 'bridge', 'managed' => false, 'used_by' => 1, 'instances' => ['hermes'], 'ipv4_address' => '', 'ipv4_nat' => false, 'ipv6_address' => '', 'dns_domain' => ''],
            ]);
        });

        $this->mock(DockerService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('networks')->once()->andReturn([
                ['id' => 'abc123', 'name' => 'bridge', 'driver' => 'bridge', 'scope' => 'local', 'internal' => false, 'ipv6' => false, 'created_at' => '', 'subnets' => [['subnet' => '172.17.0.0/16', 'gateway' => '172.17.0.1']], 'containers' => []],
            ]);
        });
    }

    public function test_the_network_page_shows_interfaces_routes_dns_and_workload_networks(): void
    {
        $this->fakeNetworkOverview();

        $this->actingAs(User::factory()->viewer()->create())
            ->get('/network')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Network/Index')
                ->where('routes.ipv4.0.gateway', '192.168.2.1')
                ->where('dns.nameservers.0', '127.0.0.53')
                ->where('listening.ports.0.port', 8090)
                ->where('firewall.backend', 'nft')
                ->where('firewallRules.rules.0.comment', 'AMP Management Instance')
                ->where('incusNetworks.0.name', 'br0')
                ->where('dockerNetworks.0.name', 'bridge')
                ->has('network')
                ->etc()
            );
    }

    public function test_operators_can_ping_a_host(): void
    {
        $this->mock(NetworkService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ping')
                ->once()
                ->with('192.168.2.1', 4)
                ->andReturn(['target' => '192.168.2.1', 'output' => '4 packets transmitted', 'exit_code' => 0, 'reachable' => true, 'duration_ms' => 120]);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/network/tools/ping', ['target' => '192.168.2.1'])
            ->assertOk()
            ->assertJson(['reachable' => true]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'network.ping']);
    }

    public function test_operators_can_look_up_dns_records(): void
    {
        $this->mock(NetworkService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dig')
                ->once()
                ->with('example.com', 'A')
                ->andReturn(['target' => 'example.com', 'record' => 'A', 'output' => 'example.com.  300 IN A 93.184.216.34', 'exit_code' => 0, 'duration_ms' => 40]);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/network/tools/dig', ['target' => 'example.com', 'record' => 'A'])
            ->assertOk()
            ->assertJsonFragment(['record' => 'A']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'network.dig']);
    }

    public function test_operators_can_check_a_tcp_port(): void
    {
        $this->mock(NetworkService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('portCheck')
                ->once()
                ->with('127.0.0.1', 22)
                ->andReturn(['target' => '127.0.0.1', 'port' => 22, 'open' => true, 'output' => 'succeeded', 'duration_ms' => 2]);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/network/tools/portcheck', ['target' => '127.0.0.1', 'port' => 22])
            ->assertOk()
            ->assertJson(['open' => true]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'network.portcheck']);
    }

    public function test_network_tool_targets_are_validated(): void
    {
        $this->mock(NetworkService::class);

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/network/tools/ping', ['target' => '-x'])
            ->assertJsonValidationErrors('target');

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/network/tools/portcheck', ['target' => '127.0.0.1', 'port' => 70000])
            ->assertJsonValidationErrors('port');

        $this->actingAs(User::factory()->operator()->create())
            ->postJson('/network/tools/dig', ['target' => 'example.com', 'record' => 'ANY'])
            ->assertJsonValidationErrors('record');
    }

    public function test_viewers_cannot_run_network_diagnostic_tools(): void
    {
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer)
            ->postJson('/network/tools/ping', ['target' => '127.0.0.1'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->postJson('/network/tools/dig', ['target' => 'example.com'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->postJson('/network/tools/portcheck', ['target' => '127.0.0.1', 'port' => 22])
            ->assertForbidden();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'network.ping']);
    }

    public function test_operators_can_add_a_firewall_rule(): void
    {
        $this->mock(NetworkService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('addFirewallRule')
                ->once()
                ->with('ipv4', 'tcp', 'accept', '8081', '203.0.113.0/24', 'game server')
                ->andReturn(['family' => 'ipv4', 'action' => 'ACCEPT', 'protocol' => 'tcp', 'port' => '8081', 'source' => '203.0.113.0/24', 'comment' => 'game server']);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/network/firewall/rules', [
                'family' => 'ipv4',
                'protocol' => 'tcp',
                'action' => 'accept',
                'port' => '8081',
                'source' => '203.0.113.0/24',
                'comment' => 'game server',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', ['action' => 'network.firewall_rule_add']);
    }

    public function test_a_port_requires_tcp_or_udp_protocol(): void
    {
        $this->mock(NetworkService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('addFirewallRule');
        });

        $this->actingAs(User::factory()->operator()->create())
            ->post('/network/firewall/rules', [
                'family' => 'ipv4',
                'protocol' => 'icmp',
                'action' => 'accept',
                'port' => '53',
            ])
            ->assertSessionHasErrors('port');
    }

    public function test_operators_can_remove_a_firewall_rule(): void
    {
        $this->mock(NetworkService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('deleteFirewallRule')
                ->once()
                ->with('ipv4', 3)
                ->andReturn(['family' => 'ipv4', 'line' => 3]);
        });

        $this->actingAs(User::factory()->operator()->create())
            ->delete('/network/firewall/rules/ipv4/3')
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('audit_logs', ['action' => 'network.firewall_rule_delete']);
    }

    public function test_viewers_cannot_manage_firewall_rules(): void
    {
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer)
            ->post('/network/firewall/rules', ['family' => 'ipv4', 'protocol' => 'tcp', 'action' => 'accept', 'port' => '8081'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->delete('/network/firewall/rules/ipv4/1')
            ->assertForbidden();
    }
}
