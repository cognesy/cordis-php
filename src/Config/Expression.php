<?php

declare(strict_types=1);

namespace CordisPhp\Config;

use CordisPhp\Exception\ExpressionException;

/**
 * A data-only configuration expression. It never contains executable PHP.
 */
final readonly class Expression
{
    public function __construct(public mixed $spec)
    {
    }

    public static function fromSpec(mixed $spec): self
    {
        if (is_string($spec)) {
            if (str_starts_with($spec, 'env:')) {
                return new self(['env' => substr($spec, strlen('env:'))]);
            }

            if (str_starts_with($spec, 'service:')) {
                return new self(['service' => substr($spec, strlen('service:'))]);
            }

            throw new ExpressionException('A scalar !expr must start with "env:" or "service:".');
        }

        if (! is_array($spec) || array_is_list($spec)) {
            throw new ExpressionException('An expression must be a mapping or a supported scalar form.');
        }

        $operations = array_values(array_intersect(array_keys($spec), ['env', 'service', 'coalesce', 'concat']));
        if (count($operations) !== 1 || ! is_string($operations[0])) {
            throw new ExpressionException('An expression must declare exactly one operation.');
        }

        $operation = $operations[0];
        $allowed = match ($operation) {
            'env' => ['env', 'default'],
            'service' => ['service'],
            'coalesce' => ['coalesce'],
            'concat' => ['concat'],
            default => throw new ExpressionException(sprintf('Unsupported expression operation "%s".', $operation)),
        };
        foreach (array_keys($spec) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw new ExpressionException(sprintf('Unsupported key "%s" for %s expression.', (string) $key, $operation));
            }
        }

        if (in_array($operation, ['env', 'service'], true)
            && (! is_string($spec[$operation]) || $spec[$operation] === '')) {
            throw new ExpressionException(sprintf('%s expressions require a non-empty service or environment name.', $operation));
        }
        if (in_array($operation, ['coalesce', 'concat'], true)
            && (! is_array($spec[$operation]) || ! array_is_list($spec[$operation]))) {
            throw new ExpressionException(sprintf('%s expressions require a list.', $operation));
        }

        return new self($spec);
    }
}
