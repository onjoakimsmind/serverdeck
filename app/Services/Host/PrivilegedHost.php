<?php

namespace App\Services\Host;

use App\Exceptions\HostCommandException;
use Symfony\Component\Process\Process;

/**
 * The only path from the web process to privileged host operations.
 *
 * The helper is root-owned and invoked with a no-argument sudo rule. All
 * parameters travel as JSON on stdin so the shell never sees user input.
 */
class PrivilegedHost
{
    public const HELPER = '/usr/local/sbin/serverdeck-hostctl';

    public function run(string $operation, array $payload = [], int $timeout = 30): array
    {
        $helper = (string) config('serverdeck.hostctl', self::HELPER);
        $process = new Process(['/usr/bin/sudo', '-n', $helper]);
        $process->setInput(json_encode(['op' => $operation] + $payload, JSON_THROW_ON_ERROR));
        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput());

            throw HostCommandException::unavailable($operation, $message ?: 'helper did not start');
        }

        $decoded = json_decode($process->getOutput(), true);

        if (! is_array($decoded) || ! ($decoded['ok'] ?? false)) {
            throw HostCommandException::unavailable(
                $operation,
                is_array($decoded) ? (string) ($decoded['error'] ?? 'unknown error') : 'unreadable helper response',
            );
        }

        $data = $decoded['data'] ?? [];

        return is_array($data) ? $data : ['value' => $data];
    }

    public function available(): bool
    {
        try {
            $this->run('systemd.list', [], 10);

            return true;
        } catch (HostCommandException) {
            return false;
        }
    }
}
