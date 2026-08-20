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
        return ['exit' => EXIT_ERROR, 'stdout' => '', 'stderr' => 'Could not start local delivery lane'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

$root = dirname(__DIR__, 3);
$lanes = ['check', 'test'];
$failures = [];

foreach ($lanes as $lane) {
    $result = runProcess(['php', $root . '/ops/bin/ops.php', 'aggregate', $lane], $root);
    fwrite(STDERR, $result['stdout'] . $result['stderr']);
    if ($result['exit'] !== EXIT_OK) {
        $failures[] = $lane;
    }
}

echo json_encode([
    'local_delivery' => [
        'status' => $failures === [] ? 'ok' : 'failed',
        'lanes' => $lanes,
        'failed' => $failures,
    ],
], JSON_PRETTY_PRINT) . "\n";

exit($failures === [] ? EXIT_OK : EXIT_ERROR);
