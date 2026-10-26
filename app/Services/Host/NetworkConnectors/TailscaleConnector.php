<?php

namespace App\Services\Host\NetworkConnectors;

use App\Services\Host\CommandRunner;
use Throwable;

/**
 * Detects a Tailscale client on this host. Read-only: `tailscale status`
 * only requires the ability to talk to the local tailscaled over its
 * socket, which does not need root, and is never shelled out to with any
 * arguments beyond a fixed, literal command.
 */
class TailscaleConnector implements NetworkConnector
{
    public function __construct(private readonly CommandRunner $runner) {}

    public function key(): string
    {
        return 'tailscale';
    }

    public function detect(): ?array
    {
        if (! $this->hasInterface() && ! $this->binaryExists()) {
            return null;
        }

        try {
            $output = $this->runner->text(['tailscale', 'status', '--json'], 3);

            if ($output === null) {
                return null;
            }

            $status = json_decode($output, true);

            if (! is_array($status)) {
                return null;
            }

            $self = $status['Self'] ?? null;

            if (! is_array($self)) {
                return null;
            }

            $ips = $self['TailscaleIPs'] ?? [];

            return [
                'hostname' => $self['DNSName'] ?? null,
                'ip' => is_array($ips) ? ($ips[0] ?? null) : null,
            ];
        } catch (Throwable) {
            // Detection is purely informational; never let it affect
            // anything else this host does.
            return null;
        }
    }

    protected function hasInterface(): bool
    {
        return is_dir('/sys/class/net/tailscale0');
    }

    private function binaryExists(): bool
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory !== '' && is_executable($directory.'/tailscale')) {
                return true;
            }
        }

        return false;
    }
}
