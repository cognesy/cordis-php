#!/usr/bin/env php
<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

const EXIT_OK = 0;
const EXIT_ERROR = 1;
const EXIT_USAGE = 2;

$runtimeRoot = dirname(__DIR__, 2);
$autoload = $runtimeRoot . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDOUT, "error:\n  code: dependencies-missing\n  message: \"Run composer install before repository operations.\"\n");
    exit(EXIT_ERROR);
}

require $autoload;

/**
 * @param list<string> $argv
 * @return array{root:string,json:bool,help:bool,positionals:list<string>,error:?string}
 */
function parseArguments(array $argv, string $defaultRoot): array
{
    $root = $defaultRoot;
    $json = false;
    $help = false;
    $positionals = [];

    for ($index = 1; $index < count($argv); $index++) {
        $argument = $argv[$index];

        if ($argument === '--json') {
            $json = true;
            continue;
        }

        if ($argument === '--help' || $argument === '-h') {
            $help = true;
            continue;
        }

        if ($argument === '--root') {
            $index++;
            if (!isset($argv[$index])) {
                return compact('root', 'json', 'help', 'positionals') + ['error' => '--root requires a path'];
            }
            $root = $argv[$index];
            continue;
        }

        if (str_starts_with($argument, '--root=')) {
            $root = substr($argument, strlen('--root='));
            continue;
        }

        if (str_starts_with($argument, '-')) {
            return compact('root', 'json', 'help', 'positionals') + ['error' => sprintf('Unknown option: %s', $argument)];
        }

        $positionals[] = $argument;
    }

    $resolved = realpath($root);

    if ($resolved === false) {
        return compact('root', 'json', 'help', 'positionals') + ['error' => sprintf('Repository root does not exist: %s', $root)];
    }

    return ['root' => $resolved, 'json' => $json, 'help' => $help, 'positionals' => $positionals, 'error' => null];
}

/** @return array<string, mixed> */
function loadYaml(string $path): array
{
    try {
        $parsed = Yaml::parseFile($path);
    } catch (Throwable $exception) {
        throw new RuntimeException(sprintf('%s: %s', $path, $exception->getMessage()), previous: $exception);
    }

    if (!is_array($parsed)) {
        throw new RuntimeException(sprintf('%s must contain a YAML object', $path));
    }

    $result = [];
    foreach ($parsed as $key => $value) {
        if (!is_string($key)) {
            throw new RuntimeException(sprintf('%s must use string object keys', $path));
        }
        $result[$key] = $value;
    }

    return $result;
}

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
        return ['exit' => EXIT_ERROR, 'stdout' => '', 'stderr' => sprintf('Could not start %s', implode(' ', $command))];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/** @return array{rule:string,file:string,message:string} */
function diagnostic(string $rule, string $file, string $message): array
{
    return compact('rule', 'file', 'message');
}

function relativePath(string $root, string $path): string
{
    return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
}

/** @return list<string> */
function repositoryFiles(string $root): array
{
    $ignored = ['.git', 'vendor', '.phpunit.cache', 'coverage', '.cache'];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );
    $files = [];

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }

        $relative = relativePath($root, $file->getPathname());
        $segments = explode('/', $relative);

        if (array_intersect($ignored, $segments) !== []) {
            continue;
        }

        if ($file->isFile()) {
            $files[] = $relative;
        }
    }

    sort($files);

    return $files;
}

function globMatches(string $pattern, string $path): bool
{
    $expression = '';
    $length = strlen($pattern);

    for ($index = 0; $index < $length; $index++) {
        $character = $pattern[$index];

        if ($character === '*' && ($pattern[$index + 1] ?? null) === '*') {
            $expression .= '.*';
            $index++;
            continue;
        }

        if ($character === '*') {
            $expression .= '[^/]*';
            continue;
        }

        if ($character === '?') {
            $expression .= '[^/]';
            continue;
        }

        $expression .= preg_quote($character, '~');
    }

    return preg_match('~^' . $expression . '$~D', $path) === 1;
}

/**
 * @param list<string> $patterns
 * @param list<string> $files
 * @return list<string>
 */
function matchingFiles(array $patterns, array $files): array
{
    $matches = [];

    foreach ($patterns as $pattern) {
        foreach ($files as $file) {
            if (globMatches($pattern, $file)) {
                $matches[$file] = true;
            }
        }
    }

    $result = array_keys($matches);
    sort($result);

    return $result;
}

/** @return list<string> */
function justRecipes(string $path): array
{
    $recipes = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        if ($line !== ltrim($line) || str_starts_with(ltrim($line), '#')) {
            continue;
        }

        if (preg_match('/^([a-z][a-z0-9-]*)(?:\s+[^:]*)?:/', $line, $matches) === 1) {
            $recipes[$matches[1]] = true;
        }
    }

    $result = array_keys($recipes);
    sort($result);

    return $result;
}

/** @return array<string, string> */
function skillFrontmatter(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false || !str_starts_with($contents, "---\n")) {
        throw new RuntimeException('Missing YAML frontmatter');
    }

    $end = strpos($contents, "\n---", 4);

    if ($end === false) {
        throw new RuntimeException('Unclosed YAML frontmatter');
    }

    $frontmatter = substr($contents, 4, $end - 4);
    $parsed = Yaml::parse($frontmatter);

    if (!is_array($parsed)) {
        throw new RuntimeException('Skill frontmatter must be an object');
    }

    $result = [];
    foreach ($parsed as $key => $value) {
        if (!is_string($key) || !is_string($value)) {
            throw new RuntimeException('Skill frontmatter values must be strings');
        }
        $result[$key] = $value;
    }

    return $result;
}

/**
 * @return list<array{directory:string,manifest_path:string,manifest:array<string,mixed>}>
 */
function capabilities(string $root): array
{
    $paths = glob($root . '/ops/*/capability.yaml') ?: [];
    sort($paths);
    $result = [];

    foreach ($paths as $path) {
        $result[] = [
            'directory' => dirname($path),
            'manifest_path' => $path,
            'manifest' => loadYaml($path),
        ];
    }

    return $result;
}

/**
 * @return list<string>
 */
function stringList(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $result = [];
    foreach ($value as $item) {
        if (is_string($item)) {
            $result[] = $item;
        }
    }

    return $result;
}

/**
 * @return list<array<string, mixed>>
 */
function objectList(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $result = [];
    foreach ($value as $item) {
        if (!is_array($item)) {
            continue;
        }

        $object = [];
        foreach ($item as $key => $property) {
            if (is_string($key)) {
                $object[$key] = $property;
            }
        }
        $result[] = $object;
    }

    return $result;
}

/**
 * @param array{directory:string,manifest_path:string,manifest:array<string,mixed>} $capability
 */
function capabilityId(array $capability): string
{
    $id = $capability['manifest']['id'] ?? null;

    return is_string($id) ? $id : 'invalid';
}

/**
 * @param list<array{directory:string,manifest_path:string,manifest:array<string,mixed>}> $capabilities
 * @return list<array{rule:string,file:string,message:string}>
 */
function schemaDiagnostics(string $root, array $capabilities): array
{
    $diagnostics = [];
    $checks = [[
        'schema' => $root . '/ops/schema/ops.v1.schema.yaml',
        'instance' => $root . '/ops/ops.yaml',
    ]];

    $versionSchema = $root . '/ops/version/schema/current.v1.schema.yaml';
    $versionRecord = $root . '/ops/version/current.yaml';
    $checks[] = [
        'schema' => $versionSchema,
        'instance' => $versionRecord,
    ];

    foreach ($capabilities as $capability) {
        $checks[] = [
            'schema' => $root . '/ops/schema/capability.v1.schema.yaml',
            'instance' => $capability['manifest_path'],
        ];

        foreach (objectList($capability['manifest']['skills'] ?? null) as $skill) {
            $name = $skill['name'] ?? null;
            if (!is_string($name)) {
                continue;
            }

            $metadata = $capability['directory'] . '/skills/' . $name . '/agents/openai.yaml';

            if (is_file($metadata)) {
                $checks[] = [
                    'schema' => $root . '/ops/schema/skill.openai.v1.schema.yaml',
                    'instance' => $metadata,
                ];
            }
        }
    }

    foreach ($checks as $check) {
        $result = runProcess(['ys', '--json', '-f', $check['schema'], $check['instance']], $root);

        if ($result['exit'] !== EXIT_OK) {
            $message = trim($result['stdout'] . "\n" . $result['stderr']);
            $diagnostics[] = diagnostic('schema-invalid', relativePath($root, $check['instance']), $message !== '' ? $message : 'ys rejected this document');
        }
    }

    return $diagnostics;
}

/**
 * @param list<array{directory:string,manifest_path:string,manifest:array<string,mixed>}> $capabilities
 * @return list<array{rule:string,file:string,message:string}>
 */
function validateCatalogue(string $root, array $capabilities): array
{
    $diagnostics = [];
    $allFiles = repositoryFiles($root);
    $opsFiles = array_values(array_filter($allFiles, static fn (string $path): bool => str_starts_with($path, 'ops/')));
    $byId = [];
    $claimedBy = [];

    try {
        $catalogue = loadYaml($root . '/ops/ops.yaml');
    } catch (RuntimeException $exception) {
        return [diagnostic('catalogue-unreadable', 'ops/ops.yaml', $exception->getMessage())];
    }

    if (($catalogue['schema_version'] ?? null) !== 'ops/v1') {
        $diagnostics[] = diagnostic('catalogue-version', 'ops/ops.yaml', 'schema_version must be ops/v1');
    }

    foreach ($capabilities as $capability) {
        $manifest = $capability['manifest'];
        $file = relativePath($root, $capability['manifest_path']);
        $id = $manifest['id'] ?? null;
        $directory = basename($capability['directory']);

        foreach (['$schema', 'schema_version', 'id', 'provides', 'description', 'status', 'requires', 'owns', 'reads', 'generates', 'commands', 'skills'] as $required) {
            if (!array_key_exists($required, $manifest)) {
                $diagnostics[] = diagnostic('manifest-required', $file, sprintf('Missing required field: %s', $required));
            }
        }

        if (($manifest['schema_version'] ?? null) !== 'ops-capability/v1') {
            $diagnostics[] = diagnostic('manifest-version', $file, 'schema_version must be ops-capability/v1');
        }

        if (!is_string($id) || $id !== $directory) {
            $diagnostics[] = diagnostic('capability-id', $file, sprintf('id must match directory name %s', $directory));
            continue;
        }

        if (isset($byId[$id])) {
            $diagnostics[] = diagnostic('duplicate-capability', $file, sprintf('Duplicate capability id: %s', $id));
            continue;
        }

        $byId[$id] = $capability;

        $justfile = $capability['directory'] . '/justfile';

        if (!is_file($justfile)) {
            $diagnostics[] = diagnostic('justfile-missing', $file, 'Capability has no justfile');
        }

        $recipes = is_file($justfile) ? justRecipes($justfile) : [];

        foreach (objectList($manifest['commands'] ?? null) as $command) {
            $name = $command['name'] ?? null;
            if (!is_string($name)) {
                continue;
            }

            if (!in_array($name, $recipes, true)) {
                $diagnostics[] = diagnostic('command-unimplemented', $file, sprintf('Command %s is not a justfile recipe', $name));
            }
        }

        foreach (objectList($manifest['skills'] ?? null) as $skill) {
            $name = $skill['name'] ?? null;
            if (!is_string($name)) {
                continue;
            }

            $skillFile = $capability['directory'] . '/skills/' . $name . '/SKILL.md';
            $metadata = dirname($skillFile) . '/agents/openai.yaml';

            if (!is_file($skillFile)) {
                $diagnostics[] = diagnostic('skill-missing', $file, sprintf('Declared skill %s has no SKILL.md', $name));
                continue;
            }

            try {
                $frontmatter = skillFrontmatter($skillFile);
                if (($frontmatter['name'] ?? null) !== $name || !is_string($frontmatter['description'] ?? null) || $frontmatter['description'] === '') {
                    $diagnostics[] = diagnostic('skill-frontmatter', relativePath($root, $skillFile), 'name must match the manifest and description must be non-empty');
                }
            } catch (RuntimeException $exception) {
                $diagnostics[] = diagnostic('skill-frontmatter', relativePath($root, $skillFile), $exception->getMessage());
            }

            if (!is_file($metadata)) {
                $diagnostics[] = diagnostic('skill-agent-metadata', relativePath($root, $skillFile), 'Missing agents/openai.yaml');
                continue;
            }

            try {
                $agent = loadYaml($metadata);
                $interface = $agent['interface'] ?? null;
                $prompt = is_array($interface) ? ($interface['default_prompt'] ?? null) : null;
                if (!is_string($prompt) || !str_contains($prompt, '$' . $name)) {
                    $diagnostics[] = diagnostic('skill-agent-prompt', relativePath($root, $metadata), sprintf('default_prompt must mention $%s', $name));
                }
            } catch (RuntimeException $exception) {
                $diagnostics[] = diagnostic('skill-agent-metadata', relativePath($root, $metadata), $exception->getMessage());
            }
        }

        foreach (['owns', 'reads', 'generates'] as $kind) {
            $rawPatterns = $manifest[$kind] ?? [];
            if (!is_array($rawPatterns)) {
                continue;
            }

            foreach ($rawPatterns as $pattern) {
                if (!is_string($pattern) || str_starts_with($pattern, '/') || str_contains($pattern, '..')) {
                    $diagnostics[] = diagnostic('path-claim', $file, sprintf('%s contains an unsafe path claim', $kind));
                }
            }
        }

        $ownedPatterns = stringList($manifest['owns'] ?? null);
        $owned = matchingFiles($ownedPatterns, $allFiles);

        foreach ($ownedPatterns as $pattern) {
            if (matchingFiles([$pattern], $allFiles) === []) {
                $diagnostics[] = diagnostic('claim-empty', $file, sprintf('Owned path claim matches no repository file: %s', $pattern));
            }
        }

        foreach ($owned as $ownedFile) {
            $claimedBy[$ownedFile][$id] = true;
        }

        $reads = matchingFiles(stringList($manifest['reads'] ?? null), $allFiles);
        foreach ($reads as $readFile) {
            if (in_array($readFile, $owned, true)) {
                $diagnostics[] = diagnostic('ambiguous-access', $file, sprintf('%s is both owned and read; use one relationship', $readFile));
            }
        }
    }

    foreach ($opsFiles as $opsFile) {
        $owners = array_keys($claimedBy[$opsFile] ?? []);
        if ($owners === []) {
            $diagnostics[] = diagnostic('unowned-ops-file', $opsFile, 'Every ops file must have one owning capability');
        }
        if (count($owners) > 1) {
            $diagnostics[] = diagnostic('ownership-overlap', $opsFile, sprintf('Claimed by %s', implode(', ', $owners)));
        }
    }

    foreach ($byId as $id => $capability) {
        $requires = $capability['manifest']['requires'] ?? null;
        $requirements = is_array($requires) ? stringList($requires['capabilities'] ?? null) : [];
        foreach ($requirements as $required) {
            if (!isset($byId[$required])) {
                $diagnostics[] = diagnostic('capability-requirement', relativePath($root, $capability['manifest_path']), sprintf('Unknown required capability: %s', $required));
            }
        }
    }

    $visiting = [];
    $visited = [];
    $walk = function (string $id) use (&$walk, &$visiting, &$visited, &$diagnostics, $byId, $root): void {
        if (isset($visited[$id])) {
            return;
        }
        if (isset($visiting[$id])) {
            $diagnostics[] = diagnostic('capability-cycle', relativePath($root, $byId[$id]['manifest_path']), sprintf('Dependency cycle reaches %s', $id));
            return;
        }
        $visiting[$id] = true;
        $requires = $byId[$id]['manifest']['requires'] ?? null;
        $requirements = is_array($requires) ? stringList($requires['capabilities'] ?? null) : [];
        foreach ($requirements as $required) {
            if (isset($byId[$required])) {
                $walk($required);
            }
        }
        unset($visiting[$id]);
        $visited[$id] = true;
    };

    foreach (array_keys($byId) as $id) {
        $walk($id);
    }

    $active = $catalogue['active'] ?? [];
    if (!is_array($active)) {
        $diagnostics[] = diagnostic('active-providers', 'ops/ops.yaml', 'active must be an object');
    } else {
        foreach ($active as $interface => $id) {
            if (!is_string($interface) || !is_string($id) || !isset($byId[$id])) {
                $diagnostics[] = diagnostic('active-provider', 'ops/ops.yaml', 'An active interface points to an unknown capability');
                continue;
            }
            if (($byId[$id]['manifest']['provides'] ?? null) !== $interface) {
                $diagnostics[] = diagnostic('active-provider', 'ops/ops.yaml', sprintf('%s must provide %s', $id, $interface));
            }
        }
    }

    return $diagnostics;
}

/** @return list<array{rule:string,file:string,message:string}> */
function validate(string $root): array
{
    try {
        $capabilities = capabilities($root);
    } catch (RuntimeException $exception) {
        return [diagnostic('manifest-unreadable', 'ops', $exception->getMessage())];
    }

    return array_merge(schemaDiagnostics($root, $capabilities), validateCatalogue($root, $capabilities));
}

function scalar(mixed $value): string
{
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    if (is_string($value)) {
        return preg_match('~^[A-Za-z0-9._/+:-]+$~D', $value) === 1
            ? $value
            : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return is_string($encoded) ? $encoded : 'unserializable';
}

function emitList(string $root, bool $json): void
{
    $capabilities = capabilities($root);
    usort($capabilities, static fn (array $left, array $right): int => capabilityId($left) <=> capabilityId($right));
    $rows = array_map(static function (array $capability): array {
        $manifest = $capability['manifest'];
        return [
            'id' => capabilityId($capability),
            'provides' => is_string($manifest['provides'] ?? null) ? $manifest['provides'] : 'invalid',
            'status' => is_string($manifest['status'] ?? null) ? $manifest['status'] : 'invalid',
            'commands' => count(objectList($manifest['commands'] ?? null)),
            'skills' => count(objectList($manifest['skills'] ?? null)),
        ];
    }, $capabilities);

    if ($json) {
        echo json_encode(['ops' => ['root' => $root, 'capabilities' => $rows]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return;
    }

    echo "ops:\n";
    echo '  root: ' . scalar($root) . "\n";
    echo '  capabilities: ' . count($rows) . "\n";
    echo 'capabilities[' . count($rows) . "]{id,provides,status,commands,skills}:\n";
    foreach ($rows as $row) {
        echo '  ' . implode(',', array_map('scalar', $row)) . "\n";
    }
    echo "help[3]:\n";
    echo "  \"just ops control validate\"\n";
    echo "  \"just ops control doctor\"\n";
    echo "  \"just ops workflow ci\"\n";
}

/** @param list<array{rule:string,file:string,message:string}> $diagnostics */
function emitDiagnostics(array $diagnostics, bool $json, string $code = 'validation-failed'): void
{
    if ($json) {
        echo json_encode(['error' => ['code' => $code, 'diagnostics' => $diagnostics]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        return;
    }

    echo "error:\n  code: " . scalar($code) . "\n  diagnostics: " . count($diagnostics) . "\n";
    echo 'diagnostics[' . count($diagnostics) . "]{rule,file,message}:\n";
    foreach ($diagnostics as $entry) {
        echo '  ' . implode(',', array_map('scalar', $entry)) . "\n";
    }
    echo "help[1]:\n  \"just ops control validate\"\n";
}

function emitValidation(bool $json, int $capabilityCount, int $schemaCount): void
{
    $payload = ['validation' => ['status' => 'ok', 'capabilities' => $capabilityCount, 'schemas' => $schemaCount]];
    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT) . "\n";
        return;
    }

    echo "validation:\n  status: ok\n  capabilities: $capabilityCount\n  schemas: $schemaCount\n";
}

function emitDoctor(string $root, bool $json): int
{
    $tools = ['php'];
    foreach (capabilities($root) as $capability) {
        $requires = $capability['manifest']['requires'] ?? null;
        $requiredTools = is_array($requires) ? stringList($requires['tools'] ?? null) : [];
        foreach ($requiredTools as $tool) {
            $tools[] = $tool;
        }
    }
    $tools = array_values(array_unique($tools));
    sort($tools);
    $rows = [];
    $missing = 0;
    foreach ($tools as $tool) {
        $result = runProcess(['which', $tool], $root);
        $path = trim($result['stdout']);
        $status = $result['exit'] === EXIT_OK && $path !== '' ? 'ready' : 'missing';
        if ($status === 'missing') {
            $missing++;
        }
        $rows[] = ['tool' => $tool, 'status' => $status, 'path' => $path === '' ? null : $path];
    }

    if ($json) {
        echo json_encode(['doctor' => ['status' => $missing === 0 ? 'ok' : 'missing-tools', 'tools' => $rows]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo "doctor:\n  status: " . ($missing === 0 ? 'ok' : 'missing-tools') . "\n  tools: " . count($rows) . "\n";
        echo 'tools[' . count($rows) . "]{tool,status,path}:\n";
        foreach ($rows as $row) {
            echo '  ' . implode(',', array_map('scalar', $row)) . "\n";
        }
    }

    return $missing === 0 ? EXIT_OK : EXIT_ERROR;
}

function aggregate(string $root, string $lane, bool $json): int
{
    if (!in_array($lane, ['check', 'test'], true)) {
        emitDiagnostics([diagnostic('usage', 'aggregate', 'Lane must be check or test')], $json, 'usage');
        return EXIT_USAGE;
    }

    $diagnostics = validate($root);
    if ($diagnostics !== []) {
        emitDiagnostics($diagnostics, $json);
        return EXIT_ERROR;
    }

    $steps = [];
    foreach (capabilities($root) as $capability) {
        foreach (objectList($capability['manifest']['commands'] ?? null) as $command) {
            $aggregate = $command['aggregate'] ?? null;
            $name = $command['name'] ?? null;
            if ($aggregate === $lane && is_string($name)) {
                $steps[] = ['capability' => capabilityId($capability), 'command' => $name, 'justfile' => $capability['directory'] . '/justfile'];
            }
        }
    }

    usort($steps, static fn (array $left, array $right): int => [$left['capability'], $left['command']] <=> [$right['capability'], $right['command']]);
    $failed = [];
    foreach ($steps as $step) {
        fwrite(STDERR, sprintf("[%s %s]\n", $step['capability'], $step['command']));
        $result = runProcess(['just', '--justfile', $step['justfile'], '--working-directory', $root, $step['command']], $root);
        if ($result['stdout'] !== '') {
            fwrite(STDERR, $result['stdout']);
        }
        if ($result['stderr'] !== '') {
            fwrite(STDERR, $result['stderr']);
        }
        if ($result['exit'] !== EXIT_OK) {
            $failed[] = $step['capability'] . ' ' . $step['command'];
        }
    }

    $payload = ['aggregate' => ['lane' => $lane, 'steps' => count($steps), 'status' => $failed === [] ? 'ok' : 'failed', 'failed' => $failed]];
    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "aggregate:\n  lane: $lane\n  steps: " . count($steps) . "\n  status: " . ($failed === [] ? 'ok' : 'failed') . "\n";
        if ($failed !== []) {
            echo 'failed[' . count($failed) . "]: \n";
            foreach ($failed as $failure) {
                echo '  ' . scalar($failure) . "\n";
            }
        }
    }

    return $failed === [] ? EXIT_OK : EXIT_ERROR;
}

function emitHelp(bool $json): void
{
    $commands = ['list', 'validate', 'doctor', 'aggregate <check|test>'];
    if ($json) {
        echo json_encode(['help' => ['description' => 'Inspect and validate Cordis PHP repository operations.', 'commands' => $commands]], JSON_PRETTY_PRINT) . "\n";
        return;
    }
    echo "ops-bin:\n  description: \"Inspect and validate Cordis PHP repository operations.\"\n";
    echo "usage:\n  \"php ops/bin/ops.php [--json] [--root <path>] <command>\"\n";
    echo 'commands[' . count($commands) . "]: \n";
    foreach ($commands as $command) {
        echo '  ' . scalar($command) . "\n";
    }
}

/** @return list<string> */
function commandLineArguments(): array
{
    $source = $_SERVER['argv'] ?? [];
    if (!is_array($source)) {
        return [];
    }

    $arguments = [];
    foreach ($source as $argument) {
        if (is_string($argument)) {
            $arguments[] = $argument;
        }
    }

    return $arguments;
}

$arguments = parseArguments(commandLineArguments(), $runtimeRoot);
if ($arguments['error'] !== null) {
    emitDiagnostics([diagnostic('usage', 'ops/bin/ops.php', $arguments['error'])], $arguments['json'], 'usage');
    exit(EXIT_USAGE);
}

if ($arguments['help']) {
    emitHelp($arguments['json']);
    exit(EXIT_OK);
}

$command = $arguments['positionals'][0] ?? 'list';
$rest = array_slice($arguments['positionals'], 1);

try {
    if ($command === 'list') {
        emitList($arguments['root'], $arguments['json']);
        exit(EXIT_OK);
    }

    if ($command === 'validate') {
        $diagnostics = validate($arguments['root']);
        if ($diagnostics !== []) {
            emitDiagnostics($diagnostics, $arguments['json']);
            exit(EXIT_ERROR);
        }
        $capabilityCount = count(capabilities($arguments['root']));
        $skillCount = array_sum(array_map(static fn (array $capability): int => count(objectList($capability['manifest']['skills'] ?? null)), capabilities($arguments['root'])));
        emitValidation($arguments['json'], $capabilityCount, 1 + $capabilityCount + $skillCount);
        exit(EXIT_OK);
    }

    if ($command === 'doctor') {
        exit(emitDoctor($arguments['root'], $arguments['json']));
    }

    if ($command === 'aggregate') {
        exit(aggregate($arguments['root'], $rest[0] ?? '', $arguments['json']));
    }

    emitDiagnostics([diagnostic('usage', 'ops/bin/ops.php', sprintf('Unknown command: %s', $command))], $arguments['json'], 'usage');
    exit(EXIT_USAGE);
} catch (Throwable $exception) {
    emitDiagnostics([diagnostic('internal-error', 'ops/bin/ops.php', $exception->getMessage())], $arguments['json'], 'internal-error');
    exit(EXIT_ERROR);
}
