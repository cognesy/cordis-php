<?php

declare(strict_types=1);

namespace CordisPhp\Runtime;

use Closure;
use CordisPhp\Exception\ServiceConflictException;
use CordisPhp\Exception\ServiceNotFoundException;

/**
 * A realm-aware dynamic service registry. Child realms may shadow or hide
 * services without mutating the enclosing application's bindings.
 */
final class ServiceRegistry
{
    /** @var array<string, ServiceBinding> */
    private array $bindings = [];

    /** @var array<string, true> */
    private array $hidden = [];

    private readonly ServiceChangeChannel $changes;

    /**
     * @param list<string> $hidden
     */
    public function __construct(
        private readonly ?self $parent = null,
        array $hidden = [],
        ?ServiceChangeChannel $changes = null,
    ) {
        foreach ($hidden as $id) {
            $this->hidden[$id] = true;
        }

        $this->changes = $changes ?? new ServiceChangeChannel();
    }

    /**
     * @param list<string> $hidden
     */
    public function isolate(array $hidden): self
    {
        return new self($this, $hidden, $this->changes);
    }

    /**
     * @return Closure(): void
     */
    public function provide(string $id, mixed $value, EffectScope $scope): Closure
    {
        if ($id === '') {
            throw new ServiceConflictException($id);
        }

        if (array_key_exists($id, $this->bindings)) {
            throw new ServiceConflictException($id);
        }

        $binding = new ServiceBinding($id, $value, $this->changes->nextVersion());
        $this->bindings[$id] = $binding;

        try {
            $dispose = $scope->deferBeforeCleanup(function () use ($id, $binding): void {
                if (($this->bindings[$id] ?? null) !== $binding) {
                    return;
                }

                unset($this->bindings[$id]);
                $this->changes->dispatch(new ServiceChange($id, ServiceChangeKind::Removed, $binding));
            }, sprintf('service:%s', $id));
        } catch (\Throwable $error) {
            unset($this->bindings[$id]);
            throw $error;
        }

        $this->changes->dispatch(new ServiceChange($id, ServiceChangeKind::Provided, $binding));

        return $dispose;
    }

    public function has(string $id): bool
    {
        return $this->binding($id) !== null;
    }

    public function lookup(string $id): mixed
    {
        return $this->binding($id)?->value;
    }

    public function require(string $id): mixed
    {
        $binding = $this->binding($id);
        if ($binding === null) {
            throw new ServiceNotFoundException($id);
        }

        return $binding->value;
    }

    public function version(string $id): ?int
    {
        return $this->binding($id)?->version;
    }

    public function binding(string $id): ?ServiceBinding
    {
        if (array_key_exists($id, $this->bindings)) {
            return $this->bindings[$id];
        }

        if (isset($this->hidden[$id])) {
            return null;
        }

        return $this->parent?->binding($id);
    }

    /**
     * @return Closure(): void
     */
    public function onChange(Closure $listener): Closure
    {
        return $this->changes->listen($listener);
    }
}

/** @internal */
final class ServiceChangeChannel
{
    /** @var array<int, Closure(ServiceChange): void> */
    private array $listeners = [];

    private int $nextId = 1;

    private int $nextVersion = 1;

    public function nextVersion(): int
    {
        return $this->nextVersion++;
    }

    /**
     * @param Closure(ServiceChange): void $listener
     * @return Closure(): void
     */
    public function listen(Closure $listener): Closure
    {
        $id = $this->nextId++;
        $this->listeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->listeners[$id]);
        };
    }

    public function dispatch(ServiceChange $change): void
    {
        foreach ($this->listeners as $listener) {
            $listener($change);
        }
    }
}
