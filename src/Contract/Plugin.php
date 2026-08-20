<?php

declare(strict_types=1);

namespace CordisPhp\Contract;

use Closure;
use CordisPhp\Runtime\Context;

interface Plugin
{
    /**
     * Return an optional cleanup closure. Registrations made through Context
     * are already tied to the plugin scope and need no manual teardown.
     */
    public function apply(Context $context, mixed $config): ?Closure;
}
