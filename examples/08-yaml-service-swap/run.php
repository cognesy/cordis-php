<?php

declare(strict_types=1);

use CordisPhp\Config\ExpressionEvaluator;
use CordisPhp\Contract\ConfigurablePlugin;
use CordisPhp\Contract\Plugin;
use CordisPhp\Exception\PluginException;
use CordisPhp\Plugin\PluginRegistry;
use CordisPhp\Runtime\Context;
use CordisPhp\Runtime\Runtime;
use Symfony\Component\Yaml\Yaml;

use function CordisPhp\Examples\expectSame;
use function CordisPhp\Examples\printResult;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/bootstrap.php';

final class Journal
{
    /** @var list<string> */
    private array $lines = [];

    public function record(string $line): void
    {
        $this->lines[] = $line;
    }

    /** @return list<string> */
    public function lines(): array
    {
        return $this->lines;
    }
}

interface NoteStore
{
    public function kind(): string;

    public function put(string $key, string $text): void;

    /** @return array<string, string> */
    public function all(): array;
}

final class MemoryNoteStore implements NoteStore
{
    /** @var array<string, string> */
    private array $notes = [];

    public function kind(): string
    {
        return 'memory';
    }

    public function put(string $key, string $text): void
    {
        $this->notes[$key] = $text;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->notes;
    }
}

final class FileNoteStore implements NoteStore
{
    public function __construct(private readonly string $path)
    {
        $this->write([]);
    }

    public function kind(): string
    {
        return 'file';
    }

    public function put(string $key, string $text): void
    {
        $notes = $this->all();
        $notes[$key] = $text;
        $this->write($notes);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $contents = file_get_contents($this->path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Could not read note store "%s".', $this->path));
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new RuntimeException('File note store must contain a JSON object.');
        }

        $notes = [];
        foreach ($decoded as $key => $text) {
            if (! is_string($key) || ! is_string($text)) {
                throw new RuntimeException('File note store must contain string keys and values.');
            }
            $notes[$key] = $text;
        }

        return $notes;
    }

    /** @param array<string, string> $notes */
    private function write(array $notes): void
    {
        $encoded = json_encode($notes, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->path, $encoded) === false) {
            throw new RuntimeException(sprintf('Could not write note store "%s".', $this->path));
        }
    }
}

final class NoteStorePlugin implements Plugin, ConfigurablePlugin
{
    public static function validateConfig(mixed $config): mixed
    {
        $driver = is_array($config) ? ($config['driver'] ?? null) : null;
        if ($driver === 'memory') {
            return ['driver' => 'memory'];
        }

        $path = is_array($config) ? ($config['path'] ?? null) : null;
        if ($driver === 'file' && is_string($path) && $path !== '') {
            return ['driver' => 'file', 'path' => $path];
        }

        throw new PluginException('Note-store config requires driver "memory" or driver "file" with a path.');
    }

    public function apply(Context $context, mixed $config): ?Closure
    {
        $driver = is_array($config) ? ($config['driver'] ?? null) : null;
        $store = match ($driver) {
            'memory' => new MemoryNoteStore(),
            'file' => new FileNoteStore(self::filePath($config)),
            default => throw new PluginException('NoteStorePlugin received unvalidated config.'),
        };

        $context->provide('notes', $store);

        return null;
    }

    private static function filePath(mixed $config): string
    {
        $path = is_array($config) ? ($config['path'] ?? null) : null;
        if (! is_string($path)) {
            throw new PluginException('File note-store config requires a path.');
        }

        return $path;
    }
}

/** @return list<array<string, mixed>> */
function readPatchFile(string $path): array
{
    $raw = Yaml::parseFile($path, Yaml::PARSE_CUSTOM_TAGS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
    if (! is_array($raw) || ! array_is_list($raw)) {
        throw new RuntimeException('A Cordis patch file must contain a list of patch mappings.');
    }

    $patches = [];
    foreach ($raw as $patch) {
        if (! is_array($patch) || array_is_list($patch)) {
            throw new RuntimeException('A Cordis patch file must contain only mappings.');
        }
        $patches[] = $patch;
    }

    return $patches;
}

$plugins = new PluginRegistry();
$plugins->registerClosure('note-journal', static function (Context $context, mixed $_config): null {
    $context->provide('journal', new Journal());

    return null;
});
$plugins->registerClass('note-store', NoteStorePlugin::class);
$plugins->registerClosure('note-writer', static function (Context $context, mixed $config): null {
    $key = is_array($config) ? ($config['key'] ?? null) : null;
    $text = is_array($config) ? ($config['text'] ?? null) : null;
    $store = $context->get('notes');
    $journal = $context->get('journal');
    if (! is_string($key) || ! is_string($text) || ! $store instanceof NoteStore || ! $journal instanceof Journal) {
        throw new PluginException('Note writer requires valid config, notes, and journal services.');
    }

    $store->put($key, $text);
    $journal->record(sprintf('writer wrote %s to %s', $key, $store->kind()));

    return null;
}, ['notes', 'journal']);
$plugins->registerClosure('note-reporter', static function (Context $context, mixed $config): ?Closure {
    $label = is_array($config) ? ($config['label'] ?? null) : null;
    $store = $context->get('notes');
    $journal = $context->get('journal');
    if (! is_string($label) || ! $store instanceof NoteStore || ! $journal instanceof Journal) {
        throw new PluginException('Note reporter requires valid config, notes, and journal services.');
    }

    $journal->record(sprintf('%s read %d from %s', $label, count($store->all()), $store->kind()));

    return static function () use ($journal, $label, $store): void {
        $journal->record(sprintf('%s released %s', $label, $store->kind()));
    };
}, ['notes', 'journal']);

$temporaryPath = tempnam(sys_get_temp_dir(), 'cordis-php-notes-');
if ($temporaryPath === false) {
    throw new RuntimeException('Could not allocate a temporary note-store path.');
}

$runtime = new Runtime($plugins, new ExpressionEvaluator(['NOTE_STORE_PATH' => $temporaryPath]));
try {
    $loader = $runtime->yaml(__DIR__.'/composition.yaml');
    $initial = $loader->reload();
    $journal = $runtime->root()->get('journal');
    if (! $journal instanceof Journal) {
        throw new RuntimeException('The composition did not provide a journal.');
    }

    $journal->record('--- applying file-store layer ---');
    $swapped = $loader->reload(readPatchFile(__DIR__.'/swap.yaml'));
    $beforeShutdown = $journal->lines();

    $contents = file_get_contents($temporaryPath);
    if ($contents === false) {
        throw new RuntimeException('The file-backed provider did not create its note store.');
    }
    $fileNotes = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

    $runtime->dispose();
    $afterShutdown = $journal->lines();

    $snapshot = [
        'initial_report' => [
            'mounted' => $initial->mounted,
            'updated' => $initial->updated,
        ],
        'swap_report' => [
            'updated' => $swapped->updated,
            'unchanged' => $swapped->unchanged,
        ],
        'before_shutdown' => $beforeShutdown,
        'after_shutdown' => $afterShutdown,
        'file_notes' => $fileNotes,
    ];

    expectSame([
        'initial_report' => [
            'mounted' => ['journal', 'store', 'writer', 'report'],
            'updated' => [],
        ],
        'swap_report' => [
            'updated' => ['store'],
            'unchanged' => ['journal', 'writer', 'report'],
        ],
        'before_shutdown' => [
            'writer wrote hello to memory',
            'main read 1 from memory',
            '--- applying file-store layer ---',
            'main released memory',
            'writer wrote hello to file',
            'main read 1 from file',
        ],
        'after_shutdown' => [
            'writer wrote hello to memory',
            'main read 1 from memory',
            '--- applying file-store layer ---',
            'main released memory',
            'writer wrote hello to file',
            'main read 1 from file',
            'main released file',
        ],
        'file_notes' => ['hello' => 'the first note'],
    ], $snapshot, 'A YAML patch must replace only the store and restart its consumers');

    printResult([
        'scenario' => 'yaml-service-swap',
        ...$snapshot,
    ]);
} finally {
    if (is_file($temporaryPath)) {
        unlink($temporaryPath);
    }
}
