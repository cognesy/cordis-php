<?php

declare(strict_types=1);

namespace CordisPhp\Runtime;

use Closure;
use CordisPhp\Exception\DisposalException;
use CordisPhp\Exception\InactiveScopeException;
use CordisPhp\Exception\PluginException;
use Throwable;

/**
 * Owns registrations and releases them in precise reverse registration order.
 */
final class EffectScope
{
    /** @var list<EffectRecord> */
    private array $records = [];

    private bool $active = true;

    private bool $disposing = false;

    public function __construct(
        public readonly string $label = 'scope',
        public readonly ?self $parent = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->active && ! $this->disposing;
    }

    public function assertActive(): void
    {
        if (! $this->isActive()) {
            throw new InactiveScopeException(sprintf('Scope "%s" is not active.', $this->label));
        }
    }

    /**
     * Register a disposer and return an idempotent handle that releases it now.
     *
     * @return Closure(): void
     */
    public function defer(Closure $disposer, string $label = 'anonymous'): Closure
    {
        $this->assertActive();

        $record = new EffectRecord($label, $disposer);
        $this->records[] = $record;

        return static function () use ($record): void {
            $record->dispose();
        };
    }

    /**
     * Run setup and register the closure it returns. Returning null is a no-op.
     *
     * @param Closure(): mixed $setup
     * @return Closure(): void
     */
    public function effect(Closure $setup, string $label = 'anonymous'): Closure
    {
        $this->assertActive();

        $result = $setup();
        if ($result === null) {
            return static function (): void {
            };
        }

        if (! $result instanceof Closure) {
            throw new PluginException(sprintf('Effect "%s" must return a Closure or null.', $label));
        }

        return $this->defer($result, $label);
    }

    public function child(string $label): self
    {
        $this->assertActive();
        $child = new self($label, $this);
        $this->defer(static function () use ($child): void {
            $child->dispose();
        }, sprintf('child:%s', $label));

        return $child;
    }

    public function dispose(): void
    {
        if (! $this->active) {
            return;
        }

        $this->disposing = true;
        $errors = [];

        foreach (array_reverse($this->records) as $record) {
            try {
                $record->dispose();
            } catch (Throwable $error) {
                $errors[] = $error;
            }
        }

        $this->active = false;
        $this->disposing = false;

        if ($errors !== []) {
            throw new DisposalException($errors);
        }
    }
}

/** @internal */
final class EffectRecord
{
    private bool $active = true;

    public function __construct(
        public readonly string $label,
        private readonly Closure $disposer,
    ) {
    }

    public function dispose(): void
    {
        if (! $this->active) {
            return;
        }

        $this->active = false;
        ($this->disposer)();
    }
}
