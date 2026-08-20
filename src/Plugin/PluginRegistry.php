<?php

declare(strict_types=1);

namespace CordisPhp\Plugin;

use Closure;
use CordisPhp\Exception\PluginException;
use CordisPhp\Runtime\Context;

final class PluginRegistry
{
    /** @var array<string, PluginDefinition> */
    private array $definitions = [];

    public function register(string $name, PluginDefinition $definition): void
    {
        if ($name === '') {
            throw new PluginException('A plugin name must not be empty.');
        }

        if (isset($this->definitions[$name])) {
            throw new PluginException(sprintf('Plugin "%s" is already registered.', $name));
        }

        $this->definitions[$name] = $definition;
    }

    /**
     * @param class-string $class
     */
    public function registerClass(string $name, string $class): void
    {
        $this->register($name, PluginDefinition::fromClass($class));
    }

    /**
     * @param Closure(Context, mixed): mixed $apply
     * @param list<string> $requires
     */
    public function registerClosure(string $name, Closure $apply, array $requires = []): void
    {
        $this->register($name, PluginDefinition::fromClosure($apply, $requires));
    }

    public function resolve(string $name): PluginDefinition
    {
        if (! isset($this->definitions[$name])) {
            throw new PluginException(sprintf('Plugin "%s" is not registered.', $name));
        }

        return $this->definitions[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }
}
