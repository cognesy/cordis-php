<?php

declare(strict_types=1);

namespace CordisPhp\Event;

use Closure;

final class Subscription
{
    private bool $active = true;

    /** @param Closure(): void $cancel */
    public function __construct(private readonly Closure $cancel)
    {
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function cancel(): void
    {
        if (! $this->active) {
            return;
        }

        $this->active = false;
        ($this->cancel)();
    }
}
