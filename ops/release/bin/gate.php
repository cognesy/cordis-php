#!/usr/bin/env php
<?php

declare(strict_types=1);

const EXIT_OK = 0;
const EXIT_ERROR = 1;

/**
 * @param list<string> $command
 * @return array{exit:int,stdout:string,stderr:string}
 */
function runProcess(array $command, string $workingDirectory): array
{
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workingDirectory,
    );

    if (!is_resource($process)) {
        return ['exit' => EXIT_ERROR, 'stdout' => '', 'stderr' => 'Could not start release gate'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

$root = dirname(__DIR__, 3);
$delivery = runProcess(['just', 'ops', 'workflow', 'ci'], $root);
fwrite(STDERR, $delivery['stdout'] . $delivery['stderr']);
$status = runProcess(['git', 'status', '--porcelain=v1'], $root);
$failures = [];

if ($delivery['exit'] !== EXIT_OK) {
    $failures[] = 'local-delivery';
}
if ($status['exit'] !== EXIT_OK || trim($status['stdout']) !== '') {
    $failures[] = 'clean-working-tree';
}

echo json_encode([
    'release_gate' => [
        'status' => $failures === [] ? 'ok' : 'failed',
        'failed' => $failures,
    ],
], JSON_PRETTY_PRINT) . "\n";

exit($failures === [] ? EXIT_OK : EXIT_ERROR);
