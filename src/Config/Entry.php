<?php

declare(strict_types=1);

namespace CordisPhp\Config;

/** A validated row from a YAML composition document. */
final readonly class Entry
{
    /**
     * @param list<string>|null $inject
     * @param list<string> $isolate
     * @param array<string, array<string, mixed>> $intercept
     * @param list<self> $group
     */
    public function __construct(
        public string $id,
        public ?string $name,
        public mixed $config = null,
        public bool|Expression $disabled = false,
        public ?array $inject = null,
        public array $isolate = [],
        public array $intercept = [],
        public array $group = [],
    ) {
    }

    public function isGroup(): bool
    {
        return $this->group !== [] || $this->name === null;
    }

    public function sameShape(self $other): bool
    {
        return $this->name === $other->name
            && $this->inject === $other->inject
            && $this->isolate === $other->isolate
            && $this->intercept === $other->intercept
            && $this->isGroup() === $other->isGroup();
    }

    /**
     * Config is YAML data, so expression objects must compare by their data
     * rather than by allocation identity on each reload.
     */
    public function sameConfig(self $other): bool
    {
        return self::sameValue($this->config, $other->config);
    }

    /** @param list<self> $group */
    public function withGroup(array $group): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->config,
            $this->disabled,
            $this->inject,
            $this->isolate,
            $this->intercept,
            $group,
        );
    }

    private static function sameValue(mixed $left, mixed $right): bool
    {
        if ($left instanceof Expression || $right instanceof Expression) {
            return $left instanceof Expression
                && $right instanceof Expression
                && self::sameValue($left->spec, $right->spec);
        }

        if (is_array($left) || is_array($right)) {
            if (! is_array($left) || ! is_array($right) || array_keys($left) !== array_keys($right)) {
                return false;
            }

            foreach ($left as $key => $value) {
                if (! self::sameValue($value, $right[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $left === $right;
    }
}
