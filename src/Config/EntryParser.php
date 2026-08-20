<?php

declare(strict_types=1);

namespace CordisPhp\Config;

use CordisPhp\Exception\ConfigurationException;
use Symfony\Component\Yaml\Tag\TaggedValue;

/**
 * Validates the closed loader envelope while leaving plugin-owned config data
 * opaque. This prevents typos in lifecycle metadata from silently changing a
 * running composition.
 */
final class EntryParser
{
    private const FIELDS = ['id', 'name', 'config', 'disabled', 'inject', 'isolate', 'intercept', 'group'];

    /**
     * @return list<Entry>
     */
    public function parseList(mixed $raw): array
    {
        $issues = [];
        $entries = $this->parseEntries($raw, '$', $issues);

        if ($issues !== []) {
            throw new ConfigurationException($this->render($issues));
        }

        return $entries;
    }

    /**
     * @param list<ConfigIssue> $issues
     * @return list<Entry>
     */
    private function parseEntries(mixed $raw, string $path, array &$issues): array
    {
        if (! is_array($raw) || ! array_is_list($raw)) {
            $issues[] = new ConfigIssue($path, 'must be a list of entries');

            return [];
        }

        $entries = [];
        $ids = [];
        foreach ($raw as $index => $row) {
            $entry = $this->parseEntry($row, sprintf('%s[%d]', $path, $index), $issues);
            if ($entry === null) {
                continue;
            }
            if (isset($ids[$entry->id])) {
                $issues[] = new ConfigIssue(sprintf('%s[%d].id', $path, $index), sprintf('duplicates id "%s"', $entry->id));

                continue;
            }
            $ids[$entry->id] = true;
            $entries[] = $entry;
        }

        return $entries;
    }

    /** @param list<ConfigIssue> $issues */
    private function parseEntry(mixed $raw, string $path, array &$issues): ?Entry
    {
        if (! is_array($raw) || array_is_list($raw)) {
            $issues[] = new ConfigIssue($path, 'must be a mapping');

            return null;
        }

        foreach ($raw as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::FIELDS, true)) {
                $issues[] = new ConfigIssue($path, sprintf('contains unsupported field "%s"', (string) $key));

                continue;
            }
            if (! in_array($key, ['config', 'disabled', 'group'], true) && $this->containsExpression($value)) {
                $issues[] = new ConfigIssue(sprintf('%s.%s', $path, $key), 'expressions are permitted only in config and disabled');
            }
        }

        $id = $raw['id'] ?? null;
        if (! is_string($id) || $id === '') {
            $issues[] = new ConfigIssue(sprintf('%s.id', $path), 'must be a non-empty string');

            return null;
        }

        $hasGroup = array_key_exists('group', $raw);
        $name = $raw['name'] ?? null;
        if ($hasGroup) {
            if ($name !== null) {
                $issues[] = new ConfigIssue(sprintf('%s.name', $path), 'must be omitted for a group entry');
            }
            if (array_key_exists('config', $raw)) {
                $issues[] = new ConfigIssue(sprintf('%s.config', $path), 'is not supported on a group entry');
            }
            $children = $this->parseEntries($raw['group'], sprintf('%s.group', $path), $issues);
        } else {
            if (! is_string($name) || $name === '') {
                $issues[] = new ConfigIssue(sprintf('%s.name', $path), 'must be a non-empty string for a plugin entry');
            }
            $children = [];
        }

        $disabled = $this->normaliseExpressions($raw['disabled'] ?? false);
        if (! is_bool($disabled) && ! $disabled instanceof Expression) {
            $issues[] = new ConfigIssue(sprintf('%s.disabled', $path), 'must be a boolean or expression');
            $disabled = false;
        }

        $inject = $this->stringList($raw['inject'] ?? null, sprintf('%s.inject', $path), $issues, true);
        $isolate = $this->stringList($raw['isolate'] ?? [], sprintf('%s.isolate', $path), $issues, false) ?? [];
        $intercept = $this->interceptions($raw['intercept'] ?? [], sprintf('%s.intercept', $path), $issues);

        return new Entry(
            $id,
            $hasGroup ? null : (is_string($name) ? $name : null),
            $hasGroup ? null : $this->normaliseExpressions($raw['config'] ?? null),
            $disabled,
            $inject,
            $isolate,
            $intercept,
            $children,
        );
    }

    /**
     * @param list<ConfigIssue> $issues
     * @return list<string>|null
     */
    private function stringList(mixed $value, string $path, array &$issues, bool $nullable): ?array
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (! is_array($value) || ! array_is_list($value)) {
            $issues[] = new ConfigIssue($path, 'must be a list of non-empty strings');

            return $nullable ? null : [];
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                $issues[] = new ConfigIssue($path, 'must be a list of non-empty strings');

                return $nullable ? null : [];
            }
            $items[] = $item;
        }
        if (count($items) !== count(array_unique($items))) {
            $issues[] = new ConfigIssue($path, 'must not contain duplicate service names');
        }

        return $items;
    }

    /**
     * @param list<ConfigIssue> $issues
     * @return array<string, array<string, mixed>>
     */
    private function interceptions(mixed $value, string $path, array &$issues): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $issues[] = new ConfigIssue($path, 'must be a mapping of service name to config mapping');

            return [];
        }

        $intercept = [];
        foreach ($value as $service => $config) {
            if (! is_string($service) || $service === '' || ! is_array($config) || array_is_list($config)) {
                $issues[] = new ConfigIssue($path, 'must be a mapping of service name to config mapping');

                continue;
            }
            $normalisedConfig = [];
            foreach ($config as $key => $item) {
                if (! is_string($key)) {
                    $issues[] = new ConfigIssue($path, 'must be a mapping of service name to config mapping');

                    continue 2;
                }
                $normalisedConfig[$key] = $item;
            }
            $intercept[$service] = $normalisedConfig;
        }

        return $intercept;
    }

    private function normaliseExpressions(mixed $value): mixed
    {
        if ($value instanceof TaggedValue) {
            if ($value->getTag() !== 'expr') {
                throw new ConfigurationException(sprintf('Unsupported YAML tag !%s.', $value->getTag()));
            }

            return Expression::fromSpec($this->normaliseExpressions($value->getValue()));
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_keys($value) === ['$expr']) {
            return Expression::fromSpec($this->normaliseExpressions($value['$expr']));
        }

        $normalised = [];
        foreach ($value as $key => $item) {
            $normalised[$key] = $this->normaliseExpressions($item);
        }

        return $normalised;
    }

    private function containsExpression(mixed $value): bool
    {
        if ($value instanceof TaggedValue) {
            return $value->getTag() === 'expr';
        }
        if (! is_array($value)) {
            return false;
        }
        if (array_keys($value) === ['$expr']) {
            return true;
        }
        foreach ($value as $item) {
            if ($this->containsExpression($item)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<ConfigIssue> $issues */
    private function render(array $issues): string
    {
        $lines = array_map(
            static fn (ConfigIssue $issue): string => sprintf('%s: %s', $issue->path, $issue->message),
            $issues,
        );

        return "Invalid Cordis YAML configuration:\n- ".implode("\n- ", $lines);
    }
}
