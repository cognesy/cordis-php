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
        return ['exit' => EXIT_ERROR, 'stdout' => '', 'stderr' => 'Could not start schema validator'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/** @return list<string> */
function compositionDocuments(string $root): array
{
    $documents = [];
    $examples = $root . '/examples';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($examples, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo) {
            continue;
        }

        if ($entry->isFile() && $entry->getExtension() === 'yaml') {
            $documents[] = $entry->getPathname();
        }
    }

    sort($documents);

    return $documents;
}

function relativePath(string $root, string $path): string
{
    return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
}

$root = dirname(__DIR__, 3);
$schema = $root . '/resources/schema/composition.schema.yaml';
$failures = [];
$validDocuments = compositionDocuments($root);

foreach ($validDocuments as $document) {
    $result = runProcess(['ys', '--json', '-f', $schema, $document], $root);
    if ($result['exit'] !== EXIT_OK) {
        $failures[] = ['file' => relativePath($root, $document), 'expectation' => 'accepted'];
        fwrite(STDERR, $result['stdout'] . $result['stderr']);
    }
}

$invalidFixture = $root . '/tests/Fixtures/composition-invalid.yaml';
$invalidResult = runProcess(['ys', '--json', '-f', $schema, $invalidFixture], $root);
if ($invalidResult['exit'] === EXIT_OK) {
    $failures[] = ['file' => relativePath($root, $invalidFixture), 'expectation' => 'rejected'];
}

$payload = [
    'composition_schema' => [
        'status' => $failures === [] ? 'ok' : 'failed',
        'accepted_documents' => count($validDocuments) - count(array_filter($failures, static fn (array $failure): bool => $failure['expectation'] === 'accepted')),
        'rejected_fixture' => $invalidResult['exit'] !== EXIT_OK,
        'failures' => $failures,
    ],
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($failures === [] ? EXIT_OK : EXIT_ERROR);
