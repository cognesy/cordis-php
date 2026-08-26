<?php

declare(strict_types=1);

namespace CordisPhp\Runtime;

use Closure;
use CordisPhp\Exception\DisposalException;
use CordisPhp\Exception\InactiveScopeException;
use CordisPhp\Exception\PluginException;
use Throwable;

/**
 * Owns registrations and releases each lifecycle phase in reverse registration
 * order, with before-cleanup records released before ordinary cleanup.
 */
final class EffectScope
{
    /** @var array<int, EffectRecord> */
    private array $beforeCleanupRecords = [];

    /** @var array<int, EffectRecord> */
    private array $records = [];

    private int $nextRecordId = 1;

    /** @var null|Closure(): void */
    private ?Closure $parentRelease = null;

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
        return $this->register($disposer, $label, false);
    }

    /**
     * Register a disposer that runs before ordinary cleanup begins.
     *
     * This phase is reserved for withdrawing externally visible capabilities
     * before the resources behind them are closed.
     *
     * @internal
     * @return Closure(): void
     */
    public function deferBeforeCleanup(Closure $disposer, string $label = 'anonymous'): Closure
    {
        return $this->register($disposer, $label, true);
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
        $child->parentRelease = $this->defer(static function () use ($child): void {
            $child->dispose();
        }, sprintf('child:%s', $label));

        return $child;
    }

    public function dispose(): void
    {
        if (! $this->active || $this->disposing) {
            return;
        }

        $this->disposing = true;
        $errors = [];
        $records = [
            ...array_reverse($this->beforeCleanupRecords),
            ...array_reverse($this->records),
        ];
        $this->beforeCleanupRecords = [];
        $this->records = [];

        foreach ($records as $record) {
            try {
                $record->dispose();
            } catch (Throwable $error) {
                $errors[] = $error;
            }
        }

        $this->active = false;
        $this->disposing = false;

        $parentRelease = $this->parentRelease;
        $this->parentRelease = null;
        if ($parentRelease !== null) {
            try {
                $parentRelease();
            } catch (Throwable $error) {
                $errors[] = $error;
            }
        }

        if ($errors !== []) {
            throw new DisposalException($errors);
        }
    }

    /**
     * @return Closure(): void
     */
    private function register(Closure $disposer, string $label, bool $beforeCleanup): Closure
    {
        $this->assertActive();

        $id = $this->nextRecordId++;
        $record = new EffectRecord($label, $disposer);
        if ($beforeCleanup) {
            $this->beforeCleanupRecords[$id] = $record;
        } else {
            $this->records[$id] = $record;
        }

        return function () use ($id, $record, $beforeCleanup): void {
            try {
                $record->dispose();
            } finally {
                $records = $beforeCleanup ? $this->beforeCleanupRecords : $this->records;
                if (($records[$id] ?? null) === $record) {
                    if ($beforeCleanup) {
                        unset($this->beforeCleanupRecords[$id]);
                    } else {
                        unset($this->records[$id]);
                    }
                }
            }
        };
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
