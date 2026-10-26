<?php

namespace App\Services\Host\NetworkConnectors;

use App\Services\Host\CommandRunner;
use Throwable;

/**
 * Detects a Twingate client on this host. Unlike Tailscale, Twingate is an
 * identity-aware proxy rather than a flat mesh network, so there is no
 * simple per-host IP to report — detection is limited to "is the client
 * installed and its service active" (see docs/design/hub.md §5.2).
 */
class TwingateConnector implements NetworkConnector
{
    public function __construct(private readonly CommandRunner $runner) {}

    public function key(): string
    {
        return 'twingate';
    }

    public function detect(): ?array
    {
        try {
            if (! $this->serviceUnitExists()) {
                return null;
            }

            return [
                'connected' => $this->serviceIsActive(),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    protected function serviceUnitExists(): bool
    {
        return is_file('/etc/systemd/system/twingate.service')
            || is_file('/usr/lib/systemd/system/twingate.service')
            || is_file('/lib/systemd/system/twingate.service');
    }

    private function serviceIsActive(): bool
    {
        // `systemctl is-active` on a specific, fixed unit name does not
        // require root and only reads state; it never mutates anything.
        return $this->runner->text(['systemctl', 'is-active', 'twingate.service'], 3) === 'active';
    }
}
