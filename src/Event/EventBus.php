<?php

declare(strict_types=1);

namespace CordisPhp\Event;

use Closure;
use CordisPhp\Runtime\EffectScope;

/**
 * Typed-by-convention extension points with Cordis' five useful dispatch modes.
 */
final class EventBus
{
    /** @var array<string, array<int, EventListener>> */
    private array $listeners = [];

    private int $nextId = 1;

    /**
     * @param Closure(mixed...): mixed $listener
     * @param null|Closure(string, array<int|string, mixed>): bool $filter
     */
    public function on(
        string $event,
        Closure $listener,
        ?EffectScope $scope = null,
        ?Closure $filter = null,
    ): Subscription {
        $id = $this->nextId++;
        $this->listeners[$event][$id] = new EventListener($listener, $filter);

        $subscription = new Subscription(function () use ($event, $id): void {
            unset($this->listeners[$event][$id]);
            if (($this->listeners[$event] ?? []) === []) {
                unset($this->listeners[$event]);
            }
        });

        if ($scope !== null) {
            $scope->defer(static function () use ($subscription): void {
                $subscription->cancel();
            }, sprintf('event:%s', $event));
        }

        return $subscription;
    }

    public function emit(string $event, mixed ...$arguments): void
    {
        foreach ($this->listenersFor($event, $arguments) as $listener) {
            ($listener->callback)(...$arguments);
        }
    }

    /**
     * PHP execution is synchronous, so parallel preserves independent-listener
     * semantics and returns every result without introducing a fake scheduler.
     *
     * @return list<mixed>
     */
    public function parallel(string $event, mixed ...$arguments): array
    {
        return $this->results($event, $arguments);
    }

    /** @return list<mixed> */
    public function serial(string $event, mixed ...$arguments): array
    {
        return $this->results($event, $arguments);
    }

    public function bail(string $event, mixed ...$arguments): mixed
    {
        foreach ($this->listenersFor($event, $arguments) as $listener) {
            $result = ($listener->callback)(...$arguments);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    public function waterfall(string $event, mixed $value, mixed ...$arguments): mixed
    {
        foreach ($this->listenersFor($event, [$value, ...$arguments]) as $listener) {
            $next = ($listener->callback)($value, ...$arguments);
            if ($next !== null) {
                $value = $next;
            }
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return list<mixed>
     */
    private function results(string $event, array $arguments): array
    {
        $results = [];
        foreach ($this->listenersFor($event, $arguments) as $listener) {
            $results[] = ($listener->callback)(...$arguments);
        }

        return $results;
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return list<EventListener>
     */
    private function listenersFor(string $event, array $arguments): array
    {
        $listeners = [];
        foreach ($this->listeners[$event] ?? [] as $listener) {
            if ($listener->filter !== null && ! ($listener->filter)($event, $arguments)) {
                continue;
            }

            $listeners[] = $listener;
        }

        return $listeners;
    }
}

/** @internal */
final readonly class EventListener
{
    /**
     * @param Closure(mixed...): mixed $callback
     * @param null|Closure(string, array<int|string, mixed>): bool $filter
     */
    public function __construct(
        public Closure $callback,
        public ?Closure $filter,
    ) {
    }
}
