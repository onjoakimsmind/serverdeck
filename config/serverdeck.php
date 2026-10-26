<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Privileged host helper
    |--------------------------------------------------------------------------
    |
    | The web process never runs host operations directly. It invokes this
    | root-owned helper through a single no-argument sudo rule. Packaged
    | installs point this at the helper installed by the release.
    |
    */

    'hostctl' => env('SERVERDECK_HOSTCTL', '/usr/local/sbin/serverdeck-hostctl'),

    /*
    |--------------------------------------------------------------------------
    | Installed release version
    |--------------------------------------------------------------------------
    |
    | Packaged releases ship a VERSION file one directory above the
    | application root (see packaging/scripts/build-release.sh). Falls back
    | to SERVERDECK_VERSION or "dev" for source checkouts that don't have
    | that file.
    |
    */

    'version' => (static function (): string {
        $versionFile = dirname(base_path()).'/VERSION';

        if (is_file($versionFile)) {
            $version = trim((string) file_get_contents($versionFile));

            if ($version !== '') {
                return $version;
            }
        }

        return env('SERVERDECK_VERSION', 'dev');
    })(),

    /*
    |--------------------------------------------------------------------------
    | Hub reporting (opt-in, off by default)
    |--------------------------------------------------------------------------
    |
    | If configured in Settings, a scheduled job POSTs a small, one-way status
    | snapshot to a ServerDeck Hub. See docs/design/hub.md. This never allows
    | the Hub to act on this host; it is a fire-and-forget outbound export.
    |
    */

    'hub' => [
        'report_interval_minutes' => (int) env('SERVERDECK_HUB_INTERVAL_MINUTES', 5),
    ],
];
