<?php

namespace App\Services\Host\NetworkConnectors;

/**
 * A small, read-only detector for an overlay/mesh network client that may be
 * running on this host (Tailscale, Twingate, ...). Used only to annotate the
 * Hub reporting payload (docs/design/hub.md §5) — detection never requires
 * new privilege and never changes host behavior.
 */
interface NetworkConnector
{
    /**
     * A short, stable key identifying this connector, e.g. "tailscale".
     */
    public function key(): string;

    /**
     * Detection result for this connector, or null if it isn't installed,
     * isn't running, or detection failed for any reason. Detection must
     * never throw: a failure here should never affect anything else this
     * host does.
     *
     * @return array<string, mixed>|null
     */
    public function detect(): ?array;
}
