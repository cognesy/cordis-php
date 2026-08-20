<?php

declare(strict_types=1);

namespace CordisPhp\Config;

use CordisPhp\Exception\ExpressionException;
use CordisPhp\Runtime\Context;
use Stringable;

/**
 * Interprets the small, whitelisted expression vocabulary used by YAML config.
 */
final readonly class ExpressionEvaluator
{
    /** @param array<string, mixed> $environment */
    public function __construct(private array $environment = [])
    {
    }

    public function evaluate(mixed $value, Context $context): mixed
    {
        if ($value instanceof Expression) {
            return $this->evaluateExpression($value, $context);
        }

        if (! is_array($value)) {
            return $value;
        }

        $resolved = [];
        foreach ($value as $key => $item) {
            $resolved[$key] = $this->evaluate($item, $context);
        }

        return $resolved;
    }

    private function evaluateExpression(Expression $expression, Context $context): mixed
    {
        $spec = $expression->spec;
        if (! is_array($spec)) {
            throw new ExpressionException('Expression state is invalid.');
        }

        if (array_key_exists('env', $spec)) {
            $name = $spec['env'];
            if (! is_string($name)) {
                throw new ExpressionException('Environment expression state is invalid.');
            }
            $value = $this->environment($name);
            if ($value !== null) {
                return $value;
            }

            return array_key_exists('default', $spec)
                ? $this->evaluate($spec['default'], $context)
                : null;
        }

        if (array_key_exists('service', $spec)) {
            $id = $spec['service'];
            if (! is_string($id)) {
                throw new ExpressionException('Service expression state is invalid.');
            }

            return $context->get($id);
        }

        if (array_key_exists('coalesce', $spec)) {
            $candidates = $spec['coalesce'];
            if (! is_array($candidates)) {
                throw new ExpressionException('Coalesce expression state is invalid.');
            }
            foreach ($candidates as $candidate) {
                $value = $this->evaluate($candidate, $context);
                if ($value !== null) {
                    return $value;
                }
            }

            return null;
        }

        if (array_key_exists('concat', $spec)) {
            $candidates = $spec['concat'];
            if (! is_array($candidates)) {
                throw new ExpressionException('Concat expression state is invalid.');
            }
            $parts = [];
            foreach ($candidates as $part) {
                $value = $this->evaluate($part, $context);
                if (! is_scalar($value) && ! $value instanceof Stringable) {
                    throw new ExpressionException('concat expressions can contain only scalar or Stringable values.');
                }
                $parts[] = (string) $value;
            }

            return implode('', $parts);
        }

        throw new ExpressionException('Expression state is invalid.');
    }

    private function environment(string $name): mixed
    {
        if (array_key_exists($name, $this->environment)) {
            return $this->environment[$name];
        }

        $value = getenv($name);

        return $value === false ? null : $value;
    }
}
