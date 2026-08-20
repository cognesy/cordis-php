<?php

declare(strict_types=1);

namespace CordisPhp\Contract;

interface ConfigurablePlugin
{
    /**
     * Validate and optionally normalize configuration before apply() runs.
     */
    public static function validateConfig(mixed $config): mixed;
}
