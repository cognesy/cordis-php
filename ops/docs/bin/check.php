#!/usr/bin/env php
<?php

declare(strict_types=1);

const EXIT_OK = 0;
const EXIT_ERROR = 1;

$root = dirname(__DIR__, 3);
$indexPath = $root . '/examples/README.md';
$index = file_get_contents($indexPath);
$failures = [];

if ($index === false) {
    $failures[] = ['file' => 'examples/README.md', 'rule' => 'example-index', 'message' => 'The example index is missing or unreadable.'];
    $index = '';
}

$runs = glob($root . '/examples/*/run.php') ?: [];
sort($runs);

foreach ($runs as $run) {
    $directory = basename(dirname($run));
    $readme = dirname($run) . '/README.md';

    if (!is_file($readme)) {
        $failures[] = ['file' => 'examples/' . $directory, 'rule' => 'example-readme', 'message' => 'Every runnable example needs a local README.md.'];
    }

    if (!str_contains($index, $directory . '/README.md')) {
        $failures[] = ['file' => 'examples/README.md', 'rule' => 'example-index', 'message' => sprintf('The index does not link to %s.', $directory)];
    }
}

echo json_encode([
    'documentation_contracts' => [
        'status' => $failures === [] ? 'ok' : 'failed',
        'runnable_examples' => count($runs),
        'failures' => $failures,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

exit($failures === [] ? EXIT_OK : EXIT_ERROR);
