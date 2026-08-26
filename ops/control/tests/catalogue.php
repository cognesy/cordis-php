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
        return ['exit' => EXIT_ERROR, 'stdout' => '', 'stderr' => 'Could not start test command'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

function copyDirectory(string $source, string $destination): void
{
    if (!mkdir($destination, 0o755, true) && !is_dir($destination)) {
        throw new RuntimeException(sprintf('Could not create %s', $destination));
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo) {
            continue;
        }

        $relative = substr($entry->getPathname(), strlen($source) + 1);
        $target = $destination . '/' . $relative;

        if ($entry->isDir()) {
            if (!mkdir($target, 0o755, true) && !is_dir($target)) {
                throw new RuntimeException(sprintf('Could not create %s', $target));
            }
            continue;
        }

        if (!copy($entry->getPathname(), $target)) {
            throw new RuntimeException(sprintf('Could not copy %s', $relative));
        }
    }
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo) {
            continue;
        }

        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($directory);
}

function fail(string $message): never
{
    echo json_encode(['control_test' => ['status' => 'failed', 'message' => $message]], JSON_PRETTY_PRINT) . "\n";
    exit(EXIT_ERROR);
}

$root = dirname(__DIR__, 3);
$validator = $root . '/ops/bin/ops.php';
$temporaryRoot = sys_get_temp_dir() . '/cordis-php-ops-catalogue-' . bin2hex(random_bytes(8));

try {
    $live = runProcess(['php', $validator, '--json', 'validate'], $root);
    if ($live['exit'] !== EXIT_OK) {
        fwrite(STDERR, $live['stderr']);
        fail('The live catalogue did not validate before the negative test.');
    }

    copyDirectory($root . '/ops', $temporaryRoot . '/ops');
    $manifest = $temporaryRoot . '/ops/test/capability.yaml';
    $contents = file_get_contents($manifest);

    if ($contents === false) {
        fail('Could not read the temporary test capability manifest.');
    }

    $replacementCount = 0;
    $changed = str_replace(
        '  - "ops/test/**"',
        "  - \"ops/test/**\"\n  - \"ops/quality/**\"",
        $contents,
        $replacementCount,
    );

    if ($replacementCount !== 1 || file_put_contents($manifest, $changed) === false) {
        fail('Could not introduce the temporary ownership conflict.');
    }

    $conflict = runProcess(['php', $validator, '--root', $temporaryRoot, '--json', 'validate'], $root);
    $payload = json_decode($conflict['stdout'], true);
    $error = is_array($payload) ? ($payload['error'] ?? null) : null;
    $diagnostics = is_array($error) ? ($error['diagnostics'] ?? []) : [];
    $hasOwnershipConflict = false;
    if (is_array($diagnostics)) {
        foreach ($diagnostics as $entry) {
            if (is_array($entry) && ($entry['rule'] ?? null) === 'ownership-overlap') {
                $hasOwnershipConflict = true;

                break;
            }
        }
    }

    if ($conflict['exit'] === EXIT_OK || !$hasOwnershipConflict) {
        fwrite(STDERR, $conflict['stdout'] . $conflict['stderr']);
        fail('The validator accepted a conflicting operations ownership claim.');
    }

    echo json_encode([
        'control_test' => [
            'status' => 'ok',
            'live_catalogue' => 'validated',
            'negative_case' => 'ownership-overlap rejected',
        ],
    ], JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $exception) {
    fail($exception->getMessage());
} finally {
    removeDirectory($temporaryRoot);
}
