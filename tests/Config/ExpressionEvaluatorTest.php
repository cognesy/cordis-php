<?php

declare(strict_types=1);

use CordisPhp\Config\Expression;
use CordisPhp\Config\ExpressionEvaluator;
use CordisPhp\Exception\ExpressionException;
use CordisPhp\Runtime\Runtime;

test('safe expressions resolve environment, services, coalescing, and concatenation', function (): void {
    $runtime = new Runtime(expressionEvaluator: new ExpressionEvaluator([
        'APP' => 'cordis',
        'EMPTY' => null,
    ]));
    $runtime->root()->provide('host', 'localhost');

    $value = $runtime->expressions()->evaluate([
        'application' => Expression::fromSpec('env:APP'),
        'endpoint' => Expression::fromSpec([
            'concat' => [
                'http://',
                new Expression(['service' => 'host']),
                ':8080',
            ],
        ]),
        'fallback' => Expression::fromSpec([
            'coalesce' => [
                new Expression(['env' => 'EMPTY']),
                'default-value',
            ],
        ]),
        'defaulted' => Expression::fromSpec(['env' => 'MISSING', 'default' => 'fallback']),
    ], $runtime->root());

    expect($value)->toBe([
        'application' => 'cordis',
        'endpoint' => 'http://localhost:8080',
        'fallback' => 'default-value',
        'defaulted' => 'fallback',
    ]);
});

test('the expression vocabulary rejects arbitrary or malformed instructions', function (): void {
    expect(fn (): Expression => Expression::fromSpec('php:system("id")'))
        ->toThrow(ExpressionException::class);
    expect(fn (): Expression => Expression::fromSpec(['env' => 'APP', 'service' => 'clock']))
        ->toThrow(ExpressionException::class);
    expect(fn (): Expression => Expression::fromSpec(['concat' => 'not-a-list']))
        ->toThrow(ExpressionException::class);
});
