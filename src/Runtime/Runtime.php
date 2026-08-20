<?php

declare(strict_types=1);

namespace CordisPhp\Runtime;

use Closure;
use CordisPhp\Config\ExpressionEvaluator;
use CordisPhp\Config\YamlRuntimeLoader;
use CordisPhp\Event\EventBus;
use CordisPhp\Exception\DisposalException;
use CordisPhp\Exception\InactiveScopeException;
use CordisPhp\Plugin\PluginDefinition;
use CordisPhp\Plugin\PluginRegistry;
use Throwable;

/**
 * Owns the root context and drives dependency-sensitive fibers to a fixed
 * point whenever service bindings change.
 */
final class Runtime
{
    private readonly ServiceRegistry $services;

    private readonly EventBus $events;

    private readonly EffectScope $scope;

    private readonly Context $root;

    /** @var array<int, Fiber> */
    private array $fibers = [];

    /** @var list<Fiber> */
    private array $roots = [];

    /** @var array<int, Closure(FiberStatusChange): void> */
    private array $statusListeners = [];

    private int $nextStatusListener = 1;

    private bool $settling = false;

    private bool $settleRequested = false;

    public function __construct(
        private readonly PluginRegistry $plugins = new PluginRegistry(),
        private readonly ExpressionEvaluator $expressionEvaluator = new ExpressionEvaluator(),
    ) {
        $this->services = new ServiceRegistry();
        $this->events = new EventBus();
        $this->scope = new EffectScope('root');
        $this->root = new Context($this, $this->services, $this->scope);
        $this->services->onChange(function (ServiceChange $_change): void {
            $this->requestSettle();
        });
    }

    public function root(): Context
    {
        return $this->root;
    }

    public function plugins(): PluginRegistry
    {
        return $this->plugins;
    }

    public function events(): EventBus
    {
        return $this->events;
    }

    public function expressions(): ExpressionEvaluator
    {
        return $this->expressionEvaluator;
    }

    /**
     * @param list<string>|null $requires
     * @param list<string> $isolate
     * @param array<string, array<string, mixed>> $intercept
     */
    public function mount(
        string|PluginDefinition $target,
        mixed $config = null,
        ?array $requires = null,
        array $isolate = [],
        array $intercept = [],
        ?Context $parent = null,
        ?string $label = null,
    ): Fiber {
        $parent ??= $this->root;
        $parent->scope()->assertActive();
        if ($parent->fiber() !== null && ! $parent->fiber()->canMountChildren()) {
            throw new InactiveScopeException('A non-live plugin context cannot mount children.');
        }

        $definition = is_string($target) ? $this->plugins->resolve($target) : $target;
        $resolvedRequires = $requires ?? $definition->requires;
        $fiber = new Fiber(
            $this,
            $parent,
            $definition,
            $config,
            $resolvedRequires,
            $isolate,
            $intercept,
            $label ?? (is_string($target) ? $target : 'plugin'),
        );

        $this->fibers[spl_object_id($fiber)] = $fiber;
        if ($parent->fiber() === null) {
            $this->roots[] = $fiber;
        } else {
            $parent->fiber()->adopt($fiber);
        }

        $fiber->attemptStart();
        $this->settle();

        return $fiber;
    }

    /** @internal */
    public function requestSettle(): void
    {
        $this->settleRequested = true;
        if (! $this->settling) {
            $this->settle();
        }
    }

    public function settle(): void
    {
        if ($this->settling) {
            $this->settleRequested = true;

            return;
        }

        $this->settling = true;
        try {
            do {
                $this->settleRequested = false;
                foreach ($this->fibers as $fiber) {
                    if ($fiber->state() === FiberState::Disposed || $fiber->state() === FiberState::Failed) {
                        continue;
                    }

                    if ($fiber->dependenciesChanged()) {
                        $fiber->restart();
                        continue;
                    }

                    if ($fiber->state() === FiberState::Pending && $fiber->attemptStart()) {
                        $this->settleRequested = true;
                    }
                }
            } while ($this->settleRequested);
        } finally {
            $this->settling = false;
        }
    }

    /**
     * @param Closure(FiberStatusChange): void $listener
     * @return Closure(): void
     */
    public function onStatus(Closure $listener): Closure
    {
        $id = $this->nextStatusListener++;
        $this->statusListeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->statusListeners[$id]);
        };
    }

    /** @internal */
    public function recordStatus(FiberStatusChange $change): void
    {
        foreach ($this->statusListeners as $listener) {
            $listener($change);
        }
    }

    /** @internal */
    public function forget(Fiber $fiber): void
    {
        unset($this->fibers[spl_object_id($fiber)]);
        $roots = [];
        foreach ($this->roots as $root) {
            if ($root !== $fiber) {
                $roots[] = $root;
            }
        }
        $this->roots = $roots;
    }

    /** @return list<Fiber> */
    public function fibers(): array
    {
        return array_values($this->fibers);
    }

    public function yaml(string $path, ?Context $context = null): YamlRuntimeLoader
    {
        return new YamlRuntimeLoader($this, $path, $context ?? $this->root);
    }

    public function dispose(): void
    {
        $errors = [];
        foreach (array_reverse($this->roots) as $fiber) {
            try {
                $fiber->dispose();
            } catch (Throwable $error) {
                $errors[] = $error;
            }
        }
        $this->roots = [];

        try {
            $this->scope->dispose();
        } catch (Throwable $error) {
            $errors[] = $error;
        }

        if ($errors !== []) {
            throw new DisposalException($errors);
        }
    }
}
