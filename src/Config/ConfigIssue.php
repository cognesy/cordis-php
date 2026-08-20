<?php

declare(strict_types=1);

namespace CordisPhp\Config;

final readonly class ConfigIssue
{
    public function __construct(
        public string $path,
        public string $message,
    ) {
    }
}
