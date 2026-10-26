<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Operator => 'Operator',
            self::Viewer => 'Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Full control of this host, including the root terminal, access management and the audit trail.',
            self::Operator => 'Can control Docker, Incus, systemd, ZFS and host processes, but cannot open a terminal or manage access.',
            self::Viewer => 'Read-only visibility into host telemetry, workloads, logs and health.',
        };
    }
}
