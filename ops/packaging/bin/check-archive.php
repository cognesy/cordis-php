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
        return ['exit' => EXIT_ERROR, 'stdout' => '', 'stderr' => 'Could not inspect Git archive'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

$root = dirname(__DIR__, 3);
$archive = tempnam(sys_get_temp_dir(), 'cordis-php-archive-');

if ($archive === false) {
    echo json_encode(['package_archive' => ['status' => 'failed', 'message' => 'Could not allocate a temporary archive path.']], JSON_PRETTY_PRINT) . "\n";
    exit(EXIT_ERROR);
}

try {
    $archiveResult = runProcess(['git', 'archive', '--worktree-attributes', '--format=tar', '--output', $archive, 'HEAD'], $root);
    if ($archiveResult['exit'] !== EXIT_OK) {
        fwrite(STDERR, $archiveResult['stdout'] . $archiveResult['stderr']);
        throw new RuntimeException('git archive failed');
    }

    $listingResult = runProcess(['tar', '-tf', $archive], $root);
    if ($listingResult['exit'] !== EXIT_OK) {
        fwrite(STDERR, $listingResult['stdout'] . $listingResult['stderr']);
        throw new RuntimeException('tar could not read the generated archive');
    }

    $entries = array_values(array_filter(array_map('trim', explode("\n", $listingResult['stdout']))));
    $entrySet = array_fill_keys($entries, true);
    $failures = [];

    foreach (['LICENSE', 'composer.json', 'src/Runtime/Runtime.php', 'resources/schema/composition.schema.yaml', 'examples/01-scoped-lifecycle/run.php'] as $required) {
        if (!isset($entrySet[$required])) {
            $failures[] = ['rule' => 'archive-required', 'path' => $required];
        }
    }

    foreach (['composer.lock', 'phpunit.xml', 'phpstan.neon', 'pint.json'] as $excluded) {
        if (isset($entrySet[$excluded])) {
            $failures[] = ['rule' => 'archive-excluded', 'path' => $excluded];
        }
    }

    foreach ($entries as $entry) {
        if (str_ends_with($entry, '/')) {
            continue;
        }

        if (str_starts_with($entry, '.github/') || str_starts_with($entry, 'tests/') || str_starts_with($entry, 'ops/')) {
            $failures[] = ['rule' => 'archive-excluded', 'path' => $entry];
        }
    }

    $opsAttribute = runProcess(['git', 'check-attr', 'export-ignore', '--', 'ops/README.md'], $root);
    if ($opsAttribute['exit'] !== EXIT_OK || !str_contains($opsAttribute['stdout'], 'export-ignore: set')) {
        $failures[] = ['rule' => 'archive-attribute', 'path' => 'ops/**'];
    }

    echo json_encode([
        'package_archive' => [
            'status' => $failures === [] ? 'ok' : 'failed',
            'entries' => count($entries),
            'failures' => $failures,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    exit($failures === [] ? EXIT_OK : EXIT_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['package_archive' => ['status' => 'failed', 'message' => $exception->getMessage()]], JSON_PRETTY_PRINT) . "\n";
    exit(EXIT_ERROR);
} finally {
    if (is_file($archive)) {
        unlink($archive);
    }
}
