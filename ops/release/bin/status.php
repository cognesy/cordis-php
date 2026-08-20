#!/usr/bin/env php
<?php

declare(strict_types=1);

const EXIT_OK = 0;

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
        return ['exit' => 1, 'stdout' => '', 'stderr' => 'Could not inspect release state'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

$root = dirname(__DIR__, 3);
$branch = runProcess(['git', 'branch', '--show-current'], $root);
$revision = runProcess(['git', 'rev-parse', '--short', 'HEAD'], $root);
$dirty = runProcess(['git', 'status', '--porcelain=v1'], $root);
$repository = runProcess(['gh', 'repo', 'view', '--json', 'nameWithOwner,url,visibility'], $root);

$repositoryData = $repository['exit'] === EXIT_OK ? json_decode($repository['stdout'], true) : null;

echo json_encode([
    'release_status' => [
        'branch' => trim($branch['stdout']),
        'revision' => trim($revision['stdout']),
        'working_tree' => trim($dirty['stdout']) === '' ? 'clean' : 'dirty',
        'github' => is_array($repositoryData) ? $repositoryData : ['status' => 'unavailable'],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if ($repository['exit'] !== EXIT_OK && $repository['stderr'] !== '') {
    fwrite(STDERR, "GitHub metadata unavailable: " . trim($repository['stderr']) . "\n");
}

exit(EXIT_OK);
