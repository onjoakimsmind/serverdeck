<?php

namespace App\Services\Host;

class ProcessService
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly PrivilegedHost $host,
    ) {}

    public function list(string $sort = 'cpu', ?string $search = null): array
    {
        $sortFlags = [
            'cpu' => '-%cpu',
            'memory' => '-%mem',
            'pid' => 'pid',
            'time' => '-etimes',
        ];

        $order = $sortFlags[$sort] ?? '-%cpu';
        $result = $this->runner->run([
            '/usr/bin/ps',
            '-eo',
            'pid=,ppid=,user:24=,pcpu=,pmem=,rss=,stat=,etimes=,comm=,args=',
            '--sort='.$order,
            '--width=300',
        ], 12);

        if (! $result['ok']) {
            return ['available' => false, 'error' => trim($result['stderr']), 'processes' => [], 'total' => 0, 'filtered' => 0];
        }

        $processes = [];
        foreach (preg_split('/\r?\n/', $result['stdout']) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $line, 10);

            if (count($parts) < 9) {
                continue;
            }

            $processes[] = [
                'pid' => (int) $parts[0],
                'ppid' => (int) $parts[1],
                'user' => $parts[2],
                'cpu' => (float) $parts[3],
                'memory' => (float) $parts[4],
                'rss' => (int) $parts[5] * 1024,
                'state' => $parts[6],
                'elapsed' => (int) $parts[7],
                'command' => $parts[8],
                'args' => $parts[9] ?? $parts[8],
            ];
        }

        $total = count($processes);

        if ($search) {
            $needle = mb_strtolower($search);
            $processes = array_values(array_filter(
                $processes,
                fn (array $process) => str_contains(mb_strtolower($process['command'].' '.$process['args'].' '.$process['user']), $needle),
            ));
        }

        $processes = array_slice($processes, 0, 300);

        return [
            'available' => true,
            'error' => null,
            'processes' => $processes,
            'total' => $total,
            'filtered' => count($processes),
        ];
    }

    public function kill(int $pid, string $signal = 'TERM'): array
    {
        return $this->host->run('process.kill', ['pid' => $pid, 'signal' => $signal], 15);
    }
}
