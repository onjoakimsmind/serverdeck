<?php

namespace Tests\Unit\Services\Host\NetworkConnectors;

use App\Services\Host\CommandRunner;
use App\Services\Host\NetworkConnectors\TailscaleConnector;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class TailscaleConnectorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_it_returns_null_when_tailscale_is_not_present(): void
    {
        $runner = Mockery::mock(CommandRunner::class);
        $runner->shouldNotReceive('text');

        $connector = new TailscaleConnector($runner);

        $this->assertNull($connector->detect());
    }

    public function test_it_returns_null_when_the_status_command_fails(): void
    {
        $runner = Mockery::mock(CommandRunner::class);
        $runner->shouldReceive('text')->andReturn(null);

        $connector = new class($runner) extends TailscaleConnector
        {
            protected function hasInterface(): bool
            {
                return true;
            }
        };

        $this->assertNull($connector->detect());
    }

    public function test_detect_never_throws_on_malformed_json(): void
    {
        $runner = Mockery::mock(CommandRunner::class);
        $runner->shouldReceive('text')->andReturn('not json');

        $connector = new class($runner) extends TailscaleConnector
        {
            protected function hasInterface(): bool
            {
                return true;
            }
        };

        $this->assertNull($connector->detect());
    }

    public function test_it_extracts_hostname_and_ip_from_valid_status_json(): void
    {
        $runner = Mockery::mock(CommandRunner::class);
        $runner->shouldReceive('text')->andReturn(json_encode([
            'Self' => [
                'DNSName' => 'web-03.tailnet-name.ts.net.',
                'TailscaleIPs' => ['100.64.12.9'],
            ],
        ]));

        $connector = new class($runner) extends TailscaleConnector
        {
            protected function hasInterface(): bool
            {
                return true;
            }
        };

        $this->assertSame([
            'hostname' => 'web-03.tailnet-name.ts.net.',
            'ip' => '100.64.12.9',
        ], $connector->detect());
    }

    public function test_the_key_is_tailscale(): void
    {
        $connector = new TailscaleConnector(Mockery::mock(CommandRunner::class));

        $this->assertSame('tailscale', $connector->key());
    }
}
