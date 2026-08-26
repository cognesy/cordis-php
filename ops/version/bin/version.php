#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

const EXIT_OK = 0;
const EXIT_ERROR = 1;
const EXIT_USAGE = 2;
const RECORD_PATH = 'ops/version/current.yaml';
const COMPOSER_PATH = 'composer.json';
const CHANGELOG_PATH = 'CHANGELOG.md';
const RELEASE_WORKFLOW_PATH = '.github/workflows/release.yml';
const PRODUCT = 'cordis-php';
const PACKAGE = 'cordis-php/cordis';
const TAG_PREFIX = 'v';

$root = dirname(__DIR__, 3);
$autoload = $root . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "error: dependencies are missing; run composer install first.\n");
    exit(EXIT_ERROR);
}

require $autoload;

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

/** @return array<string, mixed> */
function readYamlRecord(string $root, ?string $path = null): array
{
    $recordPath = $path ?? ($root . '/' . RECORD_PATH);
    try {
        $value = Yaml::parseFile($recordPath);
    } catch (Throwable $exception) {
        throw new RuntimeException(RECORD_PATH . ': ' . $exception->getMessage(), previous: $exception);
    }

    if (!is_array($value)) {
        throw new RuntimeException(RECORD_PATH . ' must contain a YAML object');
    }

    $record = [];
    foreach ($value as $key => $item) {
        if (!is_string($key)) {
            throw new RuntimeException(RECORD_PATH . ' must use string object keys');
        }
        $record[$key] = $item;
    }

    return $record;
}

function stableVersion(mixed $value): bool
{
    return is_string($value) && preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $value) === 1;
}

function compareVersions(string $left, string $right): int
{
    $leftParts = array_map('intval', explode('.', $left));
    $rightParts = array_map('intval', explode('.', $right));

    return $leftParts <=> $rightParts;
}

/** @param array<string, mixed> $record */
function releaseVersion(array $record): string
{
    $version = $record['product_version'] ?? null;
    if (!is_string($version) || !stableVersion($version)) {
        throw new RuntimeException(RECORD_PATH . ': product_version must be stable SemVer');
    }

    return $version;
}

/** @return array<string, mixed> */
function readComposer(string $root): array
{
    $contents = file_get_contents($root . '/' . COMPOSER_PATH);
    if ($contents === false) {
        throw new RuntimeException(COMPOSER_PATH . ' is missing or unreadable');
    }

    try {
        $value = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException(COMPOSER_PATH . ': ' . $exception->getMessage(), previous: $exception);
    }

    if (!is_array($value)) {
        throw new RuntimeException(COMPOSER_PATH . ' must contain a JSON object');
    }

    $composer = [];
    foreach ($value as $key => $item) {
        if (!is_string($key)) {
            throw new RuntimeException(COMPOSER_PATH . ' must use string object keys');
        }
        $composer[$key] = $item;
    }

    return $composer;
}

/** @return list<string> */
function localStableTags(string $root): array
{
    $result = runProcess(['git', 'tag', '--list', TAG_PREFIX . '*'], $root);
    if ($result['exit'] !== EXIT_OK) {
        throw new RuntimeException(trim($result['stderr'] . $result['stdout']));
    }

    $versions = [];
    foreach (array_filter(array_map('trim', explode("\n", $result['stdout']))) as $tag) {
        $version = substr($tag, strlen(TAG_PREFIX));
        if (stableVersion($version)) {
            $versions[] = $version;
        }
    }

    usort($versions, static fn (string $left, string $right): int => compareVersions($left, $right));

    return $versions;
}

/**
 * @param array<string, mixed> $record
 * @return list<string>
 */
function validationProblems(string $root, array $record, bool $checkReleaseHistory = true): array
{
    $problems = [];

    if (($record['schema_version'] ?? null) !== 'cordis-php-version/v1') {
        $problems[] = RECORD_PATH . ': schema_version must be cordis-php-version/v1';
    }
    if (($record['product'] ?? null) !== PRODUCT) {
        $problems[] = RECORD_PATH . ': product must be ' . PRODUCT;
    }
    if (($record['tag_prefix'] ?? null) !== TAG_PREFIX) {
        $problems[] = RECORD_PATH . ': tag_prefix must be ' . TAG_PREFIX;
    }

    $version = $record['product_version'] ?? null;
    if (!stableVersion($version)) {
        $problems[] = RECORD_PATH . ': product_version must be stable SemVer';
    }

    try {
        $composer = readComposer($root);
        if (($composer['name'] ?? null) !== PACKAGE) {
            $problems[] = COMPOSER_PATH . ': name must be ' . PACKAGE;
        }
    } catch (RuntimeException $exception) {
        $problems[] = $exception->getMessage();
    }

    $changelog = file_get_contents($root . '/' . CHANGELOG_PATH);
    if ($changelog === false || !is_string($version) || !str_contains($changelog, '## [' . $version . ']')) {
        $problems[] = CHANGELOG_PATH . ': must contain a heading for [' . (is_string($version) ? $version : 'current version') . ']';
    }

    $workflow = file_get_contents($root . '/' . RELEASE_WORKFLOW_PATH);
    if ($workflow === false) {
        $problems[] = RELEASE_WORKFLOW_PATH . ': release workflow is missing';
    } else {
        foreach ([
            'refs/tags/$TAG:refs/tags/$TAG',
            'just ops version verify-release',
            'just ops workflow ci',
            'just ops release package',
            'gh release create',
        ] as $required) {
            if (!str_contains($workflow, $required)) {
                $problems[] = RELEASE_WORKFLOW_PATH . ': missing release step ' . $required;
            }
        }
    }

    if ($checkReleaseHistory && is_string($version) && stableVersion($version)) {
        try {
            $tags = localStableTags($root);
            $latest = $tags === [] ? null : $tags[array_key_last($tags)];
            if ($latest !== null && compareVersions($version, $latest) < 0) {
                $problems[] = RECORD_PATH . ': version ' . $version . ' precedes local tag ' . TAG_PREFIX . $latest;
            }
        } catch (RuntimeException $exception) {
            $problems[] = $exception->getMessage();
        }
    }

    return $problems;
}

/** @param list<string> $problems */
function requireValid(string $root, array $problems): void
{
    if ($problems !== []) {
        throw new RuntimeException("Cordis PHP release version drift:\n  - " . implode("\n  - ", $problems));
    }
}

function replaceRecordVersion(string $source, string $version): string
{
    $updated = preg_replace('/^(product_version:\s*).+$/m', '${1}' . $version, $source, -1, $count);
    if ($updated === null || $count !== 1) {
        throw new RuntimeException(RECORD_PATH . ': expected exactly one product_version field');
    }

    return $updated;
}

function writeRecordVersion(string $root, string $version): void
{
    $path = $root . '/' . RECORD_PATH;
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException(RECORD_PATH . ' is missing or unreadable');
    }

    $temporary = $path . '.tmp';
    if (file_put_contents($temporary, replaceRecordVersion($source, $version)) === false) {
        throw new RuntimeException('Could not write temporary version record');
    }

    try {
        $updated = readYamlRecord($root, $temporary);
        if (!stableVersion($updated['product_version'] ?? null)) {
            throw new RuntimeException('Updated version record is invalid');
        }
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Could not replace ' . RECORD_PATH);
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function git(string $root, string ...$arguments): string
{
    /** @var list<string> $command */
    $command = array_merge(['git'], $arguments);
    $result = runProcess($command, $root);
    if ($result['exit'] !== EXIT_OK) {
        $detail = trim($result['stderr'] . $result['stdout']);
        throw new RuntimeException('git ' . implode(' ', $arguments) . ' failed: ' . $detail);
    }

    return trim($result['stdout']);
}

/**
 * @param array<string, mixed> $record
 * @return array<string, mixed>
 */
function verifyRelease(string $root, array $record): array
{
    requireValid($root, validationProblems($root, $record));
    $version = releaseVersion($record);
    $tag = TAG_PREFIX . $version;

    if (git($root, 'cat-file', '-t', 'refs/tags/' . $tag) !== 'tag') {
        throw new RuntimeException('release tag ' . $tag . ' must exist locally and be annotated');
    }

    $head = git($root, 'rev-parse', 'HEAD');
    $tagCommit = git($root, 'rev-parse', $tag . '^{commit}');
    if ($tagCommit !== $head) {
        throw new RuntimeException('release tag ' . $tag . ' points to ' . $tagCommit . ', not HEAD ' . $head);
    }

    $status = git($root, 'status', '--porcelain', '--untracked-files=all');
    if ($status !== '') {
        throw new RuntimeException("release source is not clean:\n" . $status);
    }

    $remote = git($root, 'ls-remote', '--tags', '--refs', 'origin', $tag);
    $remoteObject = null;
    foreach (explode("\n", $remote) as $line) {
        [$object, $reference] = array_pad(explode("\t", $line, 2), 2, '');
        if ($reference === 'refs/tags/' . $tag) {
            $remoteObject = $object;
            break;
        }
    }

    $localObject = git($root, 'rev-parse', 'refs/tags/' . $tag);
    if ($remoteObject !== $localObject) {
        throw new RuntimeException('release tag ' . $tag . ' is not pushed to origin with its local annotated tag object');
    }

    return [
        'schemaVersion' => 1,
        'product' => PRODUCT,
        'productVersion' => $version,
        'tag' => $tag,
        'commit' => $head,
        'tagKind' => 'annotated',
        'sourceClean' => true,
        'remoteTag' => $tag,
    ];
}

function commandUsage(): never
{
    fwrite(STDERR, "usage: version.php show|value|check|next <part>|set <version>|sync|verify-release\n");
    exit(EXIT_USAGE);
}

$command = $argv[1] ?? null;

try {
    if ($command === 'show') {
        $record = readYamlRecord($root);
        $problems = validationProblems($root, $record);
        $tags = localStableTags($root);
        echo json_encode([
            'schemaVersion' => 1,
            'product' => PRODUCT,
            'productVersion' => $record['product_version'] ?? null,
            'authority' => RECORD_PATH,
            'package' => PACKAGE,
            'latestLocalTag' => $tags === [] ? null : TAG_PREFIX . $tags[array_key_last($tags)],
            'valid' => $problems === [],
            'problems' => $problems,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit($problems === [] ? EXIT_OK : EXIT_ERROR);
    }

    if ($command === 'value') {
        echo releaseVersion(readYamlRecord($root)) . "\n";
        exit(EXIT_OK);
    }

    if ($command === 'check') {
        $record = readYamlRecord($root);
        requireValid($root, validationProblems($root, $record));
        echo 'Cordis PHP release version ' . releaseVersion($record) . ': OK' . "\n";
        exit(EXIT_OK);
    }

    if ($command === 'next') {
        $part = $argv[2] ?? null;
        if (!in_array($part, ['patch', 'minor', 'major'], true)) {
            throw new RuntimeException('next requires patch, minor, or major');
        }
        $record = readYamlRecord($root);
        requireValid($root, validationProblems($root, $record));
        [$major, $minor, $patch] = array_map('intval', explode('.', releaseVersion($record)));
        echo match ($part) {
            'major' => ($major + 1) . '.0.0',
            'minor' => $major . '.' . ($minor + 1) . '.0',
            default => $major . '.' . $minor . '.' . ($patch + 1),
        } . "\n";
        exit(EXIT_OK);
    }

    if ($command === 'set') {
        $requested = $argv[2] ?? '';
        if (!stableVersion($requested)) {
            throw new RuntimeException('version must be stable SemVer');
        }
        if (git($root, 'status', '--porcelain', '--untracked-files=all') !== '') {
            throw new RuntimeException('the working tree is dirty; commit or stash first');
        }
        $record = readYamlRecord($root);
        requireValid($root, validationProblems($root, $record, false));
        $current = releaseVersion($record);
        if (compareVersions($requested, $current) <= 0) {
            throw new RuntimeException('version ' . $requested . ' must be greater than ' . $current);
        }
        $tags = localStableTags($root);
        $latest = $tags === [] ? null : $tags[array_key_last($tags)];
        if ($latest !== null && compareVersions($requested, $latest) <= 0) {
            throw new RuntimeException('version ' . $requested . ' must be greater than local tag ' . TAG_PREFIX . $latest);
        }
        writeRecordVersion($root, $requested);
        echo 'Cordis PHP release version: ' . $current . ' -> ' . $requested . "\n";
        exit(EXIT_OK);
    }

    if ($command === 'sync') {
        git($root, 'fetch', '--tags', '--prune', 'origin');
        echo "Release tags from origin: synchronized\n";
        exit(EXIT_OK);
    }

    if ($command === 'verify-release') {
        $result = verifyRelease($root, readYamlRecord($root));
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(EXIT_OK);
    }

    commandUsage();
} catch (Throwable $exception) {
    fwrite(STDERR, 'version: ' . $exception->getMessage() . "\n");
    exit(EXIT_ERROR);
}
