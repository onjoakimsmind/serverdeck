<?php

namespace App\Exceptions;

use RuntimeException;

class HostCommandException extends RuntimeException
{
    public static function unavailable(string $operation, string $message): self
    {
        return new self("Host operation [{$operation}] failed: {$message}");
    }
}
