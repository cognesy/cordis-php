<?php

declare(strict_types=1);

namespace CordisPhp\Runtime;

use CordisPhp\Exception\InactiveScopeException;
use CordisPhp\Exception\PluginException;
use CordisPhp\Plugin\PluginDefinition;
use Throwable;

/**
 * One mounted plugin instance. It stays pending until all declared services
 * exist and restarts whenever one of those binding identities changes.
 */
final class Fiber
{
    private FiberState $state = FiberState::Pending;

    private ?Throwable $error = null;

    private Context $context;

    private EffectScope $scope;

    /** @var array<string, int|null> */
    private array $dependencyVersions = [];

    /** @var list<string> */
    private array $missing = [];

    /** @var array<int, Fiber> */
    private array $children = [];

    private mixed $resolvedConfig = null;

    /**
     * @param list<string> $requires
     * @param list<string> $isolate
     * @param array<string, array<string, mixed>> $intercept
     */
    public function __construct(
        private readonly Runtime $runtime,
        private readonly Context $parent,
        private readonly PluginDefinition $definition,
        private mixed $rawConfig,
        private readonly array $requires,
        private readonly array $isolate,
        private readonly array $intercept,
        private readonly string $label,
    ) {
        $this->beginAttempt();
    }

    public function state(): FiberState
    {
        return $this->state;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function error(): ?Throwable
    {
        return $this->error;
    }

    public function context(): Context
    {
        return $this->context;
    }

    public function scope(): EffectScope
    {
        return $this->scope;
    }

    /** @return list<string> */
    public function requires(): array
    {
        return $this->requires;
    }

    /** @return list<string> */
    public function missing(): array
    {
        return $this->missing;
    }

    public function config(): mixed
    {
        return $this->resolvedConfig;
    }

    public function isLive(): bool
    {
        return in_array($this->state, [FiberState::Pending, FiberState::Loading, FiberState::Active], true);
    }

    public function canMountChildren(): bool
    {
        return in_array($this->state, [FiberState::Loading, FiberState::Active], true) && $this->scope->isActive();
    }

    /** @internal */
    public function attemptStart(): bool
    {
        if ($this->state !== FiberState::Pending) {
            return false;
        }

        $this->missing = array_values(array_filter(
            $this->requires,
            fn (string $service): bool => ! $this->context->has($service),
        ));
        if ($this->missing !== []) {
            return false;
        }

        $this->transition(FiberState::Loading);

        try {
            $this->resolvedConfig = $this->definition->validate(
                $this->runtime->expressions()->evaluate($this->rawConfig, $this->context),
            );
            $plugin = $this->definition->instantiate();
            $cleanup = $plugin->apply($this->context, $this->resolvedConfig);
            if ($cleanup !== null) {
                $this->scope->defer($cleanup, sprintf('plugin:%s', $this->label));
            }
            if (! $this->scope->isActive()) {
                throw new PluginException(sprintf('Plugin "%s" disposed its scope during startup.', $this->label));
            }

            foreach ($this->requires as $service) {
                $this->dependencyVersions[$service] = $this->context->serviceVersion($service);
            }
            $this->transition(FiberState::Active);
        } catch (Throwable $error) {
            $this->error = $error;
            try {
                $this->scope->dispose();
            } catch (Throwable) {
                // The startup exception is the most useful first cause. Scope
                // disposal remains best effort and releases every registration.
            }
            $this->transition(FiberState::Failed);
        }

        return true;
    }

    public function update(mixed $config): void
    {
        if ($this->rawConfig === $config) {
            return;
        }

        $this->rawConfig = $config;
        $this->restart();
    }

    public function restart(): void
    {
        if ($this->state === FiberState::Disposed) {
            throw new InactiveScopeException(sprintf('Fiber "%s" is disposed.', $this->label));
        }

        try {
            $this->unloadToPending();
        } catch (Throwable $error) {
            // EffectScope always releases every record before reporting its
            // aggregate errors. The fiber can therefore become a visible,
            // recoverable failure instead of remaining stuck in Unloading.
            $this->error = $error;
            $this->transition(FiberState::Failed);

            return;
        }
        $this->runtime->requestSettle();
    }

    public function dispose(): void
    {
        if ($this->state === FiberState::Disposed) {
            return;
        }

        if ($this->state !== FiberState::Unloading) {
            $this->transition(FiberState::Unloading);
        }

        try {
            $this->scope->dispose();
        } finally {
            $this->dependencyVersions = [];
            $this->missing = [];
            $this->children = [];
            try {
                $this->transition(FiberState::Disposed);
            } finally {
                $this->runtime->forget($this);
            }
        }
    }

    /** @internal */
    public function dependenciesChanged(): bool
    {
        if ($this->state !== FiberState::Active) {
            return false;
        }

        foreach ($this->requires as $service) {
            if (($this->dependencyVersions[$service] ?? null) !== $this->context->serviceVersion($service)) {
                return true;
            }
        }

        return false;
    }

    /** @internal */
    public function adopt(Fiber $child): void
    {
        if (! $this->canMountChildren()) {
            throw new InactiveScopeException(sprintf('Fiber "%s" cannot mount a child.', $this->label));
        }

        $id = spl_object_id($child);
        $this->children[$id] = $child;
        $this->scope->defer(function () use ($child, $id): void {
            try {
                $child->dispose();
            } finally {
                unset($this->children[$id]);
            }
        }, sprintf('child:%s', $child->label()));
    }

    private function unloadToPending(): void
    {
        if ($this->state !== FiberState::Unloading) {
            $this->transition(FiberState::Unloading);
        }

        try {
            $this->scope->dispose();
        } finally {
            $this->dependencyVersions = [];
            $this->missing = [];
            $this->children = [];
            $this->error = null;
        }
        $this->beginAttempt();
        $this->transition(FiberState::Pending);
    }

    private function beginAttempt(): void
    {
        $this->context = $this->parent->child($this->label, $this->isolate, $this->intercept);
        $this->scope = $this->context->scope();
        $this->context->attachFiber($this);
    }

    private function transition(FiberState $next): void
    {
        if ($next === $this->state) {
            return;
        }

        $allowed = [
            FiberState::Pending->value => [FiberState::Loading, FiberState::Unloading],
            FiberState::Loading->value => [FiberState::Active, FiberState::Failed, FiberState::Unloading],
            FiberState::Active->value => [FiberState::Unloading],
            FiberState::Failed->value => [FiberState::Unloading],
            FiberState::Unloading->value => [FiberState::Pending, FiberState::Failed, FiberState::Disposed],
            FiberState::Disposed->value => [],
        ];

        if (! in_array($next, $allowed[$this->state->value], true)) {
            throw new \LogicException(sprintf('Illegal fiber transition %s -> %s.', $this->state->value, $next->value));
        }

        $previous = $this->state;
        $this->state = $next;
        $this->runtime->recordStatus(new FiberStatusChange($this, $previous, $next));
    }
}
