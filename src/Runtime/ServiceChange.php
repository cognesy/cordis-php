<?php

declare(strict_types=1);

namespace CordisPhp\Runtime;

final readonly class ServiceChange
{
    public function __construct(
        public string $id,
        public ServiceChangeKind $kind,
        public ?ServiceBinding $binding,
    ) {
    }
}
