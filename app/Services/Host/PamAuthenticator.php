<?php

namespace App\Services\Host;

use App\Exceptions\HostCommandException;

class PamAuthenticator
{
    public function __construct(private readonly PrivilegedHost $host) {}

    /**
     * Validate a username and password against the host's PAM stack.
     * Returns the Linux account record on success, null on failure.
     */
    public function authenticate(string $username, string $password): ?array
    {
        try {
            return $this->host->run('auth', [
                'username' => $username,
                'password' => $password,
            ], 20);
        } catch (HostCommandException) {
            return null;
        }
    }
}
