<?php

declare(strict_types=1);

namespace CordisPhp\Plugin;

use Closure;
use CordisPhp\Contract\Plugin;
use CordisPhp\Exception\PluginException;
use CordisPhp\Runtime\Context;

final readonly class CallablePlugin implements Plugin
{
    /** @param Closure(Context, mixed): mixed $apply */
    public function __construct(private Closure $apply)
    {
    }

    public function apply(Context $context, mixed $config): ?Closure
    {
        $cleanup = ($this->apply)($context, $config);
        if ($cleanup !== null && ! $cleanup instanceof Closure) {
            throw new PluginException('A plugin callback must return a cleanup Closure or null.');
        }

        return $cleanup;
    }
}
