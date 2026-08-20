<?php

declare(strict_types=1);

namespace CordisPhp\Runtime;

use Closure;
use CordisPhp\Event\EventBus;
use CordisPhp\Event\Subscription;
use CordisPhp\Plugin\PluginDefinition;

/**
 * A scoped view of services, events, metadata, and plugin ownership.
 */
final class Context
{
    /** @var array<string, mixed> */
    private array $metadata;

    /** @var array<string, array<string, mixed>> */
    private array $interceptions;

    private ?Fiber $fiber = null;

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, array<string, mixed>> $interceptions
     */
    public function __construct(
        private readonly Runtime $runtime,
        private readonly ServiceRegistry $services,
        private readonly EffectScope $scope,
        array $metadata = [],
        array $interceptions = [],
    ) {
        $this->metadata = $metadata;
        $this->interceptions = $interceptions;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function extend(array $metadata): self
    {
        $context = new self(
            $this->runtime,
            $this->services,
            $this->scope,
            [...$this->metadata, ...$metadata],
            $this->interceptions,
        );

        // An extended context is still a view owned by the same plugin. This
        // matters when a plugin uses it to mount a child: ownership must not
        // silently escape to the runtime root.
        $context->fiber = $this->fiber;

        return $context;
    }

    /**
     * @param list<string> $isolate
     * @param array<string, array<string, mixed>> $interceptions
     */
    public function child(string $label, array $isolate = [], array $interceptions = []): self
    {
        $registry = $isolate === [] ? $this->services : $this->services->isolate($isolate);

        return new self(
            $this->runtime,
            $registry,
            $this->scope->child($label),
            $this->metadata,
            [...$this->interceptions, ...$interceptions],
        );
    }

    /** @internal */
    public function attachFiber(Fiber $fiber): void
    {
        if ($this->fiber !== null) {
            throw new \LogicException('A Context can belong to only one Fiber.');
        }

        $this->fiber = $fiber;
    }

    public function fiber(): ?Fiber
    {
        return $this->fiber;
    }

    public function scope(): EffectScope
    {
        return $this->scope;
    }

    public function events(): EventBus
    {
        return $this->runtime->events();
    }

    public function runtime(): Runtime
    {
        return $this->runtime;
    }

    /**
     * @return Closure(): void
     */
    public function provide(string $id, mixed $value): Closure
    {
        return $this->services->provide($id, $value, $this->scope);
    }

    public function has(string $id): bool
    {
        return $this->services->has($id);
    }

    public function get(string $id): mixed
    {
        return $this->services->require($id);
    }

    public function lookup(string $id): mixed
    {
        return $this->services->lookup($id);
    }

    public function serviceVersion(string $id): ?int
    {
        return $this->services->version($id);
    }

    /**
     * @param Closure(mixed...): mixed $listener
     * @param null|Closure(string, array<int|string, mixed>): bool $filter
     */
    public function on(string $event, Closure $listener, ?Closure $filter = null): Subscription
    {
        return $this->events()->on($event, $listener, $this->scope, $filter);
    }

    /**
     * @param list<string>|null $requires
     * @param list<string> $isolate
     * @param array<string, array<string, mixed>> $intercept
     */
    public function plugin(
        string|PluginDefinition $target,
        mixed $config = null,
        ?array $requires = null,
        array $isolate = [],
        array $intercept = [],
        ?string $label = null,
    ): Fiber {
        return $this->runtime->mount($target, $config, $requires, $isolate, $intercept, $this, $label);
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /** @return array<string, mixed> */
    public function interceptionFor(string $service): array
    {
        return $this->interceptions[$service] ?? [];
    }
}
