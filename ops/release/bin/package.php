#!/usr/bin/env php
<?php

declare(strict_types=1);

const EXIT_OK = 0;
const EXIT_ERROR = 1;
const PACKAGE = 'cordis-php/cordis';

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
        return ['exit' => EXIT_ERROR, 'stdout' => '', 'stderr' => 'Could not start ' . implode(' ', $command)];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

function fail(string $message): never
{
    throw new RuntimeException($message);
}

set_exception_handler(static function (Throwable $exception): void {
    fwrite(STDERR, 'release package: ' . $exception->getMessage() . "\n");
    exit(EXIT_ERROR);
});

$root = dirname(__DIR__, 3);
$versionScript = $root . '/ops/version/bin/version.php';
$versionResult = runProcess(['php', $versionScript, 'value'], $root);
if ($versionResult['exit'] !== EXIT_OK) {
    fwrite(STDERR, $versionResult['stdout'] . $versionResult['stderr']);
    exit(EXIT_ERROR);
}
$version = trim($versionResult['stdout']);

$verifyResult = runProcess(['php', $versionScript, 'verify-release'], $root);
if ($verifyResult['exit'] !== EXIT_OK) {
    fwrite(STDERR, $verifyResult['stdout'] . $verifyResult['stderr']);
    exit(EXIT_ERROR);
}

$packagingResult = runProcess(['just', 'ops', 'packaging', 'archive'], $root);
if ($packagingResult['exit'] !== EXIT_OK) {
    fwrite(STDERR, $packagingResult['stdout'] . $packagingResult['stderr']);
    exit(EXIT_ERROR);
}

$output = $argv[1] ?? ($root . '/dist/release');
$output = str_starts_with($output, '/') ? $output : $root . '/' . $output;
$output = rtrim($output, '/');
if ($output === '' || $output === $root) {
    fail('release output must be a dedicated directory');
}
if (!is_dir($output) && !mkdir($output, 0777, true) && !is_dir($output)) {
    fail('could not create release output directory: ' . $output);
}

$prefix = 'cordis-php-' . $version . '/';
$archiveName = 'cordis-php-' . $version . '.tar.gz';
$archivePath = $output . '/' . $archiveName;
$archiveResult = runProcess([
    'git',
    'archive',
    '--worktree-attributes',
    '--format=tar.gz',
    '--prefix=' . $prefix,
    '--output=' . $archivePath,
    'HEAD',
], $root);
if ($archiveResult['exit'] !== EXIT_OK) {
    fwrite(STDERR, $archiveResult['stdout'] . $archiveResult['stderr']);
    exit(EXIT_ERROR);
}

$listingResult = runProcess(['tar', '-tzf', $archivePath], $root);
if ($listingResult['exit'] !== EXIT_OK) {
    fwrite(STDERR, $listingResult['stdout'] . $listingResult['stderr']);
    exit(EXIT_ERROR);
}

$entries = array_values(array_filter(array_map('trim', explode("\n", $listingResult['stdout']))));
$entrySet = array_fill_keys($entries, true);
$failures = [];
foreach ([$prefix . 'LICENSE', $prefix . 'README.md', $prefix . 'composer.json', $prefix . 'src/Runtime/Runtime.php'] as $required) {
    if (!isset($entrySet[$required])) {
        $failures[] = 'archive is missing ' . $required;
    }
}
foreach ($entries as $entry) {
    $relative = substr($entry, strlen($prefix));
    if (str_starts_with($relative, '.github/') || str_starts_with($relative, 'ops/') || str_starts_with($relative, 'skills/') || str_starts_with($relative, 'tests/')) {
        $failures[] = 'development path leaked into archive: ' . $relative;
    }
    if (in_array($relative, ['composer.lock', 'phpunit.xml', 'phpstan.neon', 'pint.json'], true)) {
        $failures[] = 'development file leaked into archive: ' . $relative;
    }
}
if ($failures !== []) {
    fail(implode("\n", $failures));
}

$digest = hash_file('sha256', $archivePath);
if ($digest === false) {
    fail('could not hash ' . $archiveName);
}
$checksums = $output . '/SHA256SUMS.txt';
if (file_put_contents($checksums, $digest . '  ' . $archiveName . "\n") === false) {
    fail('could not write SHA256SUMS.txt');
}

$commitResult = runProcess(['git', 'rev-parse', 'HEAD'], $root);
if ($commitResult['exit'] !== EXIT_OK) {
    fail('could not determine the release commit');
}
$manifest = [
    'schemaVersion' => 1,
    'product' => 'cordis-php',
    'package' => PACKAGE,
    'productVersion' => $version,
    'tag' => 'v' . $version,
    'commit' => trim($commitResult['stdout']),
    'archive' => [
        'filename' => $archiveName,
        'sha256' => $digest,
    ],
];
$manifestPath = $output . '/release-manifest.json';
if (file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
    fail('could not write release-manifest.json');
}

echo json_encode([
    'release_package' => [
        'status' => 'ok',
        'outputDirectory' => $output,
        'version' => $version,
        'archive' => $archiveName,
        'checksum' => 'SHA256SUMS.txt',
        'manifest' => 'release-manifest.json',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
