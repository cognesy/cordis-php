<?php

declare(strict_types=1);

namespace CordisPhp\Config;

use CordisPhp\Exception\PatchException;

/** Applies deterministic id-targeted overlays before entry validation. */
final class PatchApplicator
{
    /**
     * @param list<mixed> $entries
     * @param list<mixed> $patches
     * @return list<mixed>
     */
    public static function apply(array $entries, array $patches): array
    {
        foreach ($patches as $index => $rawPatch) {
            $path = sprintf('$patches[%d]', $index);
            self::applyOne($entries, self::mapping($rawPatch, $path), $path);
        }

        return $entries;
    }

    /**
     * @param list<mixed> $entries
     * @param array<string, mixed> $patch
     */
    private static function applyOne(array &$entries, array $patch, string $path): void
    {
        $allowed = ['id', 'name', 'config', 'disabled', 'inject', 'isolate', 'intercept', 'group', 'insert'];
        foreach (array_keys($patch) as $field) {
            if (! in_array($field, $allowed, true)) {
                throw new PatchException(sprintf('%s contains unsupported field "%s".', $path, $field));
            }
        }

        $id = $patch['id'] ?? null;
        if ($id !== null && (! is_string($id) || $id === '')) {
            throw new PatchException(sprintf('%s.id must be a non-empty string.', $path));
        }

        if (array_key_exists('insert', $patch)) {
            foreach (array_keys($patch) as $field) {
                if (! in_array($field, ['id', 'insert'], true)) {
                    throw new PatchException(sprintf('%s cannot combine insert with "%s".', $path, $field));
                }
            }
            $insert = self::entryList($patch['insert'], sprintf('%s.insert', $path));
            if ($id === null) {
                foreach ($insert as $entry) {
                    $entries[] = $entry;
                }

                return;
            }

            if (! self::insertInto($entries, $id, $insert, $path)) {
                throw new PatchException(sprintf('%s targets missing entry "%s".', $path, $id));
            }

            return;
        }

        if ($id === null) {
            throw new PatchException(sprintf('%s requires id unless it inserts at the root.', $path));
        }

        if (! self::overlay($entries, $id, $patch, $path)) {
            throw new PatchException(sprintf('%s targets missing entry "%s".', $path, $id));
        }
    }

    /**
     * @param list<mixed> $entries
     * @param list<array<string, mixed>> $insert
     */
    private static function insertInto(array &$entries, string $id, array $insert, string $path): bool
    {
        foreach ($entries as &$candidate) {
            if (! is_array($candidate) || array_is_list($candidate)) {
                continue;
            }

            if (($candidate['id'] ?? null) === $id) {
                $group = $candidate['group'] ?? null;
                if (! is_array($group) || ! array_is_list($group)) {
                    throw new PatchException(sprintf('%s inserts into "%s", which is not a group.', $path, $id));
                }
                $children = $group;
                foreach ($insert as $entry) {
                    $children[] = $entry;
                }
                $candidate['group'] = $children;
                unset($candidate);

                return true;
            }

            $group = $candidate['group'] ?? null;
            if (is_array($group) && array_is_list($group)) {
                $children = $group;
                if (self::insertInto($children, $id, $insert, $path)) {
                    $candidate['group'] = $children;
                    unset($candidate);

                    return true;
                }
            }
        }
        unset($candidate);

        return false;
    }

    /**
     * @param list<mixed> $entries
     * @param array<string, mixed> $patch
     */
    private static function overlay(array &$entries, string $id, array $patch, string $path): bool
    {
        foreach ($entries as &$candidate) {
            if (! is_array($candidate) || array_is_list($candidate)) {
                continue;
            }

            if (($candidate['id'] ?? null) === $id) {
                if (array_key_exists('name', $patch) && ($candidate['name'] ?? null) !== $patch['name']) {
                    throw new PatchException(sprintf('%s expected a matching name on "%s".', $path, $id));
                }
                foreach ($patch as $field => $value) {
                    if (in_array($field, ['id', 'name'], true)) {
                        continue;
                    }
                    $candidate[$field] = $value;
                }
                unset($candidate);

                return true;
            }

            $group = $candidate['group'] ?? null;
            if (is_array($group) && array_is_list($group)) {
                $children = $group;
                if (self::overlay($children, $id, $patch, $path)) {
                    $candidate['group'] = $children;
                    unset($candidate);

                    return true;
                }
            }
        }
        unset($candidate);

        return false;
    }

    /** @return list<array<string, mixed>> */
    private static function entryList(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new PatchException(sprintf('%s must be a list of entries.', $path));
        }

        $entries = [];
        foreach ($value as $index => $entry) {
            $entries[] = self::mapping($entry, sprintf('%s[%d]', $path, $index));
        }

        return $entries;
    }

    /** @return array<string, mixed> */
    private static function mapping(mixed $value, string $path): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new PatchException(sprintf('%s must be a mapping.', $path));
        }

        $mapping = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new PatchException(sprintf('%s must use string field names.', $path));
            }
            $mapping[$key] = $item;
        }

        return $mapping;
    }
}
