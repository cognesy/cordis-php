<?php

declare(strict_types=1);

namespace CordisPhp\Runtime;

final readonly class ServiceBinding
{
    public function __construct(
        public string $id,
        public mixed $value,
        public int $version,
    ) {
    }
}
