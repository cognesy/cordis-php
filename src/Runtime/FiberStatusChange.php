<?php

declare(strict_types=1);

namespace CordisPhp\Runtime;

final readonly class FiberStatusChange
{
    public function __construct(
        public Fiber $fiber,
        public FiberState $previous,
        public FiberState $current,
    ) {
    }
}
