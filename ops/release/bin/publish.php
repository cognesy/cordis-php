#!/usr/bin/env php
<?php

declare(strict_types=1);

const EXIT_OK = 0;
const EXIT_ERROR = 1;
const EXIT_USAGE = 2;

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
        return ['exit' => EXIT_ERROR, 'stdout' => '', 'stderr' => 'Could not start release publishing'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

$version = $argv[1] ?? '';
if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $version) !== 1) {
    echo json_encode(['error' => ['code' => 'usage', 'message' => 'Version must be semantic: MAJOR.MINOR.PATCH with an optional prerelease.']], JSON_PRETTY_PRINT) . "\n";
    exit(EXIT_USAGE);
}

$root = dirname(__DIR__, 3);
$gate = runProcess(['just', 'ops', 'release', 'gate'], $root);
fwrite(STDERR, $gate['stdout'] . $gate['stderr']);
if ($gate['exit'] !== EXIT_OK) {
    echo json_encode(['release_publish' => ['status' => 'blocked', 'reason' => 'release-gate']], JSON_PRETTY_PRINT) . "\n";
    exit(EXIT_ERROR);
}

$tag = 'v' . $version;
$tagCheck = runProcess(['git', 'rev-parse', '--verify', '--quiet', 'refs/tags/' . $tag], $root);
if ($tagCheck['exit'] !== EXIT_OK) {
    echo json_encode(['release_publish' => ['status' => 'blocked', 'reason' => 'missing-tag', 'tag' => $tag]], JSON_PRETTY_PRINT) . "\n";
    exit(EXIT_ERROR);
}

$release = runProcess(['gh', 'release', 'create', $tag, '--verify-tag', '--generate-notes', '--title', 'Cordis PHP ' . $tag], $root);
fwrite(STDERR, $release['stderr']);

echo json_encode([
    'release_publish' => [
        'status' => $release['exit'] === EXIT_OK ? 'created' : 'failed',
        'tag' => $tag,
        'url' => trim($release['stdout']) === '' ? null : trim($release['stdout']),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

exit($release['exit'] === EXIT_OK ? EXIT_OK : EXIT_ERROR);
