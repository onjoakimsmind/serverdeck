<?php

namespace App\Services\Host;

use Symfony\Component\Process\Process;

class CommandRunner
{
    public function run(array $command, int $timeout = 10): array
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exit_code' => $process->getExitCode(),
        ];
    }

    public function text(array $command, int $timeout = 10): ?string
    {
        $result = $this->run($command, $timeout);

        return $result['ok'] ? trim($result['stdout']) : null;
    }
}
