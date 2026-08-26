<?php

declare(strict_types=1);

namespace CordisPhp\Config;

use Closure;
use CordisPhp\Exception\ConfigurationException;
use CordisPhp\Plugin\PluginDefinition;
use CordisPhp\Runtime\Context;
use CordisPhp\Runtime\Fiber;
use CordisPhp\Runtime\FiberState;
use CordisPhp\Runtime\Runtime;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Reads a YAML composition file and reconciles it against live plugin fibers.
 */
final class YamlRuntimeLoader
{
    /** @var array<string, LoadedEntry> */
    private array $live = [];

    private readonly EntryParser $parser;

    private ?string $lastRevision = null;

    public function __construct(
        private readonly Runtime $runtime,
        private readonly string $path,
        private readonly Context $context,
        private readonly LoaderLimits $limits = new LoaderLimits(),
    ) {
        $this->parser = new EntryParser($this->limits, $this->path);
    }

    /**
     * @param list<array<string, mixed>> $patches
     */
    public function reload(array $patches = []): ReconcileReport
    {
        $raw = $this->readDocument();
        if ($patches !== []) {
            $raw = PatchApplicator::apply($raw, $patches);
        }

        $report = $this->reconcile($this->parser->parseList($raw));
        $this->lastRevision = $this->documentRevision();

        return $report;
    }

    /**
     * @param list<array<string, mixed>> $patches
     */
    public function reloadIfChanged(array $patches = []): ?ReconcileReport
    {
        $revision = $this->documentRevision();
        if ($this->lastRevision !== null && $revision === $this->lastRevision) {
            return null;
        }

        return $this->reload($patches);
    }

    /**
     * @param list<Entry> $entries
     */
    public function reconcile(array $entries): ReconcileReport
    {
        $changes = new Reconciliation();
        $enabled = $this->enabledEntries($entries, '', $changes);
        if ($changes->failed !== []) {
            return $changes->report();
        }

        $this->preflight($enabled, '', $changes);
        if ($changes->failed !== []) {
            return $changes->report();
        }

        $this->reconcileLevel($enabled, $this->live, $this->context, '', $changes);
        $this->runtime->settle();

        return $changes->report();
    }

    /** @return list<string> */
    public function live(): array
    {
        $paths = [];
        $this->collectPaths($this->live, '', $paths);

        return $paths;
    }

    public function dispose(): void
    {
        foreach (array_reverse($this->live, true) as $id => $entry) {
            $this->disposeEntry($entry, $id, new Reconciliation());
        }
        $this->live = [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readDocument(): array
    {
        try {
            $raw = Yaml::parse(
                $this->readSource(),
                Yaml::PARSE_CUSTOM_TAGS | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE,
            );
        } catch (ParseException $error) {
            throw new ConfigurationException(sprintf('Could not parse "%s": %s', $this->path, $error->getMessage()), 0, $error);
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            throw new ConfigurationException('The root of a Cordis YAML composition must be an entry list.');
        }

        /** @var list<array<string, mixed>> $raw */
        return $raw;
    }

    private function documentRevision(): string
    {
        return hash('sha256', $this->readSource());
    }

    private function readSource(): string
    {
        if (! is_file($this->path)) {
            throw new ConfigurationException(sprintf('YAML configuration file "%s" does not exist.', $this->path));
        }

        $stream = fopen($this->path, 'rb');
        if ($stream === false) {
            throw new ConfigurationException(sprintf('Could not read YAML configuration file "%s".', $this->path));
        }

        try {
            $source = stream_get_contents($stream, $this->limits->maxBytes + 1);
        } finally {
            fclose($stream);
        }

        if ($source === false) {
            throw new ConfigurationException(sprintf('Could not read YAML configuration file "%s".', $this->path));
        }
        if (strlen($source) > $this->limits->maxBytes) {
            throw new ConfigurationException(sprintf(
                'YAML configuration file "%s" exceeds the maximum size of %d bytes.',
                $this->path,
                $this->limits->maxBytes,
            ));
        }

        return $source;
    }

    /**
     * @param list<Entry> $entries
     * @return list<Entry>
     */
    private function enabledEntries(array $entries, string $prefix, Reconciliation $changes): array
    {
        $enabled = [];
        foreach ($entries as $entry) {
            $path = $prefix.$entry->id;
            if ($entry->disabled instanceof Expression) {
                try {
                    $disabled = $this->runtime->expressions()->evaluate($entry->disabled, $this->context);
                    if (! is_bool($disabled)) {
                        throw new ConfigurationException('disabled expressions must resolve to a boolean');
                    }
                } catch (Throwable $error) {
                    $changes->failed[] = new EntryFailure($path, 'disabled expression', $error);

                    continue;
                }
            } else {
                $disabled = $entry->disabled;
            }

            if ($disabled) {
                continue;
            }
            if ($entry->isGroup()) {
                $entry = $entry->withGroup($this->enabledEntries($entry->group, $path.'.', $changes));
            }
            $enabled[] = $entry;
        }

        return $enabled;
    }

    /** @param list<Entry> $entries */
    private function preflight(array $entries, string $prefix, Reconciliation $changes): void
    {
        foreach ($entries as $entry) {
            $path = $prefix.$entry->id;
            if (! $entry->isGroup()) {
                try {
                    $this->runtime->plugins()->resolve((string) $entry->name);
                } catch (Throwable $error) {
                    $changes->failed[] = new EntryFailure($path, 'unresolvable plugin', $error);
                }
            }
            $this->preflight($entry->group, $path.'.', $changes);
        }
    }

    /**
     * @param list<Entry> $desired
     * @param array<string, LoadedEntry> $live
     */
    private function reconcileLevel(
        array $desired,
        array &$live,
        Context $parent,
        string $prefix,
        Reconciliation $changes,
    ): void {
        $wanted = [];
        foreach ($desired as $entry) {
            $wanted[$entry->id] = true;
            $path = $prefix.$entry->id;
            $current = $live[$entry->id] ?? null;

            if ($current === null) {
                $mounted = $this->mountEntry($entry, $parent, $path, $changes);
                if ($mounted !== null) {
                    $live[$entry->id] = $mounted;
                }

                continue;
            }

            if (! $current->entry->sameShape($entry)) {
                $this->disposeEntry($current, $path, $changes);
                unset($live[$entry->id]);
                $mounted = $this->mountEntry($entry, $parent, $path, $changes);
                if ($mounted !== null) {
                    $live[$entry->id] = $mounted;
                }

                continue;
            }

            $previous = $current->entry;
            $current->entry = $entry;
            if ($entry->isGroup()) {
                $this->reconcileLevel($entry->group, $current->children, $current->fiber->context(), $path.'.', $changes);

                continue;
            }

            if (! $previous->sameConfig($entry)) {
                $current->fiber->update($entry->config);
                $changes->updated[] = $path;
                if ($current->fiber->state() === FiberState::Failed && $current->fiber->error() !== null) {
                    $changes->failed[] = new EntryFailure($path, 'plugin startup', $current->fiber->error());
                }
            } else {
                $changes->unchanged[] = $path;
            }
        }

        foreach (array_keys($live) as $id) {
            if (isset($wanted[$id])) {
                continue;
            }
            $path = $prefix.$id;
            $this->disposeEntry($live[$id], $path, $changes);
            unset($live[$id]);
        }
    }

    private function mountEntry(Entry $entry, Context $parent, string $path, Reconciliation $changes): ?LoadedEntry
    {
        try {
            $target = $entry->isGroup()
                ? PluginDefinition::fromClosure(static function (Context $_context, mixed $_config): ?Closure {
                    return null;
                })
                : (string) $entry->name;
            $fiber = $parent->plugin($target, $entry->config, $entry->inject, $entry->isolate, $entry->intercept, $path);
            $loaded = new LoadedEntry($entry, $fiber);
            $changes->mounted[] = $path;
            if ($fiber->state() === FiberState::Failed && $fiber->error() !== null) {
                $changes->failed[] = new EntryFailure($path, 'plugin startup', $fiber->error());

                return $loaded;
            }
            if ($entry->isGroup()) {
                $this->reconcileLevel($entry->group, $loaded->children, $fiber->context(), $path.'.', $changes);
            }

            return $loaded;
        } catch (Throwable $error) {
            $changes->failed[] = new EntryFailure($path, 'plugin startup', $error);

            return null;
        }
    }

    private function disposeEntry(LoadedEntry $entry, string $path, Reconciliation $changes): void
    {
        $this->collectPaths([$entry->entry->id => $entry], $this->parentPrefix($path), $changes->disposed);
        try {
            $entry->fiber->dispose();
        } catch (Throwable $error) {
            $changes->failed[] = new EntryFailure($path, 'plugin disposal', $error);
        }
    }

    /**
     * @param array<string, LoadedEntry> $entries
     * @param list<string> $paths
     */
    private function collectPaths(array $entries, string $prefix, array &$paths): void
    {
        foreach ($entries as $id => $entry) {
            $path = $prefix.$id;
            $paths[] = $path;
            $this->collectPaths($entry->children, $path.'.', $paths);
        }
    }

    private function parentPrefix(string $path): string
    {
        $position = strrpos($path, '.');

        return $position === false ? '' : substr($path, 0, $position + 1);
    }
}

/** @internal */
final class LoadedEntry
{
    /** @var array<string, self> */
    public array $children = [];

    public function __construct(
        public Entry $entry,
        public readonly Fiber $fiber,
    ) {
    }
}

/** @internal */
final class Reconciliation
{
    /** @var list<string> */
    public array $mounted = [];

    /** @var list<string> */
    public array $updated = [];

    /** @var list<string> */
    public array $disposed = [];

    /** @var list<string> */
    public array $unchanged = [];

    /** @var list<EntryFailure> */
    public array $failed = [];

    public function report(): ReconcileReport
    {
        return new ReconcileReport($this->mounted, $this->updated, $this->disposed, $this->unchanged, $this->failed);
    }
}
