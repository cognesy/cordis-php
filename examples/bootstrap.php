<?php

declare(strict_types=1);

namespace CordisPhp\Examples;

use RuntimeException;

/**
 * Fail an example before it prints a result when its deterministic contract
 * changes. This keeps the CLI output useful as executable documentation.
 */
function expectSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected === $actual) {
        return;
    }

    throw new RuntimeException(sprintf(
        "%s failed.\nExpected: %s\nActual: %s",
        $label,
        var_export($expected, true),
        var_export($actual, true),
    ));
}

/** @param array<string, mixed> $result */
function printResult(array $result): void
{
    echo json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ).PHP_EOL;
}
