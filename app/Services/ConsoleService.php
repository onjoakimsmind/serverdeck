<?php

namespace App\Services;

use App\Exceptions\ConsoleException;
use App\Exceptions\HostCommandException;
use App\Services\Host\PrivilegedHost;
use Illuminate\Support\Facades\Cache;

class ConsoleService
{
    private const MAX_SESSIONS = 4;

    private const SESSION_TTL_HOURS = 2;

    public function __construct(private readonly PrivilegedHost $host) {}

    /**
     * Open a PTY-backed console session owned by the given panel user.
     *
     * @return array{session: string, target: string, kind: string, mode: string, name: string, title: string, cols: int, rows: int}
     */
    public function open(int $userId, string $target, string $kind, string $mode, string $name, int $cols, int $rows): array
    {
        $this->prune($userId);

        if (count($this->userSessions($userId)) >= self::MAX_SESSIONS) {
            throw new ConsoleException('Close an existing console before opening another one.');
        }

        $session = bin2hex(random_bytes(16));

        $data = $this->host->run('console.open', [
            'session' => $session,
            'target' => $target,
            'kind' => $kind,
            'mode' => $mode,
            'name' => $name,
            'cols' => $cols,
            'rows' => $rows,
        ], 20);

        $meta = [
            'user_id' => $userId,
            'session' => $session,
            'target' => $target,
            'kind' => $kind,
            'mode' => $mode,
            'name' => $name,
            'title' => (string) ($data['title'] ?? $name),
            'opened_at' => now()->toIso8601String(),
        ];

        Cache::put($this->sessionKey($session), $meta, now()->addHours(self::SESSION_TTL_HOURS));

        $sessions = $this->userSessions($userId);
        $sessions[] = $session;
        Cache::put($this->userKey($userId), array_values(array_unique($sessions)), now()->addHours(self::SESSION_TTL_HOURS));

        return [
            'session' => $session,
            'target' => $target,
            'kind' => $kind,
            'mode' => $mode,
            'name' => $name,
            'title' => $meta['title'],
            'cols' => $cols,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{data: string, offset: int, closed: bool, exit_code: int|null, lost: bool}
     */
    public function read(int $userId, string $session, int $offset): array
    {
        $this->authorize($userId, $session);

        try {
            return $this->host->run('console.read', [
                'session' => $session,
                'offset' => $offset,
            ], 15);
        } catch (HostCommandException $exception) {
            $this->translateEnded($userId, $session, $exception);
            throw $exception;
        }
    }

    /**
     * @return array{written: int}
     */
    public function write(int $userId, string $session, string $data): array
    {
        $this->authorize($userId, $session);

        try {
            return $this->host->run('console.write', [
                'session' => $session,
                'data' => $data,
            ], 15);
        } catch (HostCommandException $exception) {
            $this->translateEnded($userId, $session, $exception);
            throw $exception;
        }
    }

    /**
     * @return array{cols: int, rows: int}
     */
    public function resize(int $userId, string $session, int $cols, int $rows): array
    {
        $this->authorize($userId, $session);

        try {
            return $this->host->run('console.resize', [
                'session' => $session,
                'cols' => $cols,
                'rows' => $rows,
            ], 15);
        } catch (HostCommandException $exception) {
            $this->translateEnded($userId, $session, $exception);
            throw $exception;
        }
    }

    /**
     * Close a session and return its metadata, or null when it already ended.
     *
     * @return array<string, mixed>|null
     */
    public function close(int $userId, string $session): ?array
    {
        $meta = $this->meta($userId, $session);

        if ($meta === null) {
            return null;
        }

        try {
            $this->host->run('console.close', ['session' => $session], 10);
        } catch (\Throwable) {
            // The daemon may already have exited; the session is still forgotten below.
        }

        $this->forget($userId, $session);

        return $meta;
    }

    private function authorize(int $userId, string $session): void
    {
        if ($this->meta($userId, $session) === null) {
            throw new ConsoleException('Console session not found or expired.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function meta(int $userId, string $session): ?array
    {
        $meta = Cache::get($this->sessionKey($session));

        if (! is_array($meta) || (int) ($meta['user_id'] ?? 0) !== $userId) {
            return null;
        }

        return $meta;
    }

    private function translateEnded(int $userId, string $session, HostCommandException $exception): void
    {
        if (! str_contains($exception->getMessage(), 'Console session has ended')) {
            return;
        }

        $this->forget($userId, $session);

        throw new ConsoleException('Console session has ended.', previous: $exception);
    }

    private function forget(int $userId, string $session): void
    {
        Cache::forget($this->sessionKey($session));

        $sessions = array_values(array_filter(
            $this->userSessions($userId),
            fn ($item) => $item !== $session,
        ));

        Cache::put($this->userKey($userId), $sessions, now()->addHours(self::SESSION_TTL_HOURS));
    }

    private function prune(int $userId): void
    {
        $sessions = array_values(array_filter(
            $this->userSessions($userId),
            fn ($session) => Cache::has($this->sessionKey($session)),
        ));

        Cache::put($this->userKey($userId), $sessions, now()->addHours(self::SESSION_TTL_HOURS));
    }

    /**
     * @return array<int, string>
     */
    private function userSessions(int $userId): array
    {
        $sessions = Cache::get($this->userKey($userId), []);

        return is_array($sessions) ? array_values(array_filter($sessions, 'is_string')) : [];
    }

    private function sessionKey(string $session): string
    {
        return 'console.session.'.$session;
    }

    private function userKey(int $userId): string
    {
        return 'console.user.'.$userId;
    }
}
