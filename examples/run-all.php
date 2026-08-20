<?php

declare(strict_types=1);

$examples = [
    '01-scoped-lifecycle',
    '02-dynamic-service-restart',
    '03-yaml-live-reload',
    '04-event-pipeline',
    '05-runtime-observability',
    '06-tenant-isolation',
    '07-configuration-validation',
    '08-yaml-service-swap',
    '09-service-interception',
];

foreach ($examples as $example) {
    $command = sprintf(
        '%s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__DIR__."/$example/run.php"),
    );
    $lines = [];
    $status = 0;
    exec($command, $lines, $status);

    if ($status !== 0) {
        throw new RuntimeException(sprintf('%s failed with exit status %d.', $example, $status));
    }

    echo "=== $example ===\n";
    echo implode(PHP_EOL, $lines).PHP_EOL;
}
