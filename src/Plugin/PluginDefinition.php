<?php

declare(strict_types=1);

namespace CordisPhp\Plugin;

use Closure;
use CordisPhp\Contract\ConfigurablePlugin;
use CordisPhp\Contract\Plugin;
use CordisPhp\Contract\RequiresServices;
use CordisPhp\Exception\PluginException;
use CordisPhp\Runtime\Context;

final readonly class PluginDefinition
{
    /**
     * @param Closure(): mixed $factory
     * @param list<string> $requires
     * @param null|Closure(mixed): mixed $validator
     */
    public function __construct(
        private Closure $factory,
        public array $requires = [],
        private ?Closure $validator = null,
    ) {
    }

    /**
     * @param class-string $class
     */
    public static function fromClass(string $class): self
    {
        if (! is_a($class, Plugin::class, true)) {
            throw new PluginException(sprintf('%s must implement %s.', $class, Plugin::class));
        }

        $requires = is_a($class, RequiresServices::class, true)
            ? $class::requiredServices()
            : [];
        $validator = is_a($class, ConfigurablePlugin::class, true)
            ? static fn (mixed $config): mixed => $class::validateConfig($config)
            : null;

        return new self(static fn (): Plugin => new $class(), $requires, $validator);
    }

    /**
     * @param Closure(Context, mixed): mixed $apply
     * @param list<string> $requires
     */
    public static function fromClosure(Closure $apply, array $requires = []): self
    {
        return new self(
            static fn (): Plugin => new CallablePlugin($apply),
            $requires,
        );
    }

    public function instantiate(): Plugin
    {
        $plugin = ($this->factory)();
        if (! $plugin instanceof Plugin) {
            throw new PluginException('A plugin factory must return a Plugin instance.');
        }

        return $plugin;
    }

    public function validate(mixed $config): mixed
    {
        return $this->validator === null ? $config : ($this->validator)($config);
    }
}
