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
];
