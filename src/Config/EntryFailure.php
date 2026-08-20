<?php

declare(strict_types=1);

namespace CordisPhp\Config;

use Throwable;

final readonly class EntryFailure
{
    public function __construct(
        public string $path,
        public string $reason,
        public Throwable $error,
    ) {
    }
}
