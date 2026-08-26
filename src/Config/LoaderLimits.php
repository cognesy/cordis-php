<?php

declare(strict_types=1);

namespace CordisPhp\Config;

use InvalidArgumentException;

/** Bounded resource budget for one YAML composition load. */
final readonly class LoaderLimits
{
    public function __construct(
        public int $maxBytes = 1_048_576,
        public int $maxEntries = 10_000,
        public int $maxNesting = 64,
    ) {
        if ($this->maxBytes < 1 || $this->maxBytes === PHP_INT_MAX) {
            throw new InvalidArgumentException('The YAML byte limit must be between 1 and PHP_INT_MAX - 1.');
        }
        if ($this->maxEntries < 1) {
            throw new InvalidArgumentException('The YAML entry limit must be at least 1.');
        }
        if ($this->maxNesting < 1) {
            throw new InvalidArgumentException('The YAML nesting limit must be at least 1.');
        }
    }
}
