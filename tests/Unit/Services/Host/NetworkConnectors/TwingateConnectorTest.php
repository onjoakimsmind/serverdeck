<?php

namespace Tests\Unit\Services\Host\NetworkConnectors;

use App\Services\Host\CommandRunner;
use App\Services\Host\NetworkConnectors\TwingateConnector;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class TwingateConnectorTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_it_returns_null_when_twingate_is_not_installed(): void
    {
        $runner = Mockery::mock(CommandRunner::class);
        $runner->shouldNotReceive('text');

        $connector = new TwingateConnector($runner);

        $this->assertNull($connector->detect());
    }

    public function test_it_reports_connected_true_when_the_service_is_active(): void
    {
        $runner = Mockery::mock(CommandRunner::class);
        $runner->shouldReceive('text')->andReturn('active');

        $connector = new class($runner) extends TwingateConnector
        {
            protected function serviceUnitExists(): bool
            {
                return true;
            }
        };

        $this->assertSame(['connected' => true], $connector->detect());
    }

    public function test_it_reports_connected_false_when_the_service_is_installed_but_inactive(): void
    {
        $runner = Mockery::mock(CommandRunner::class);
        $runner->shouldReceive('text')->andReturn('inactive');

        $connector = new class($runner) extends TwingateConnector
        {
            protected function serviceUnitExists(): bool
            {
                return true;
            }
        };

        $this->assertSame(['connected' => false], $connector->detect());
    }

    public function test_the_key_is_twingate(): void
    {
        $connector = new TwingateConnector(Mockery::mock(CommandRunner::class));

        $this->assertSame('twingate', $connector->key());
    }
}
