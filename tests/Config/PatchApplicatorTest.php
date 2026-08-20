<?php

declare(strict_types=1);

use CordisPhp\Config\PatchApplicator;
use CordisPhp\Exception\PatchException;

test('patches overlay nested entries and insert at group or root boundaries', function (): void {
    $entries = [
        [
            'id' => 'stack',
            'group' => [
                ['id' => 'logger', 'name' => 'logger', 'config' => ['level' => 'info']],
            ],
        ],
    ];

    $patched = PatchApplicator::apply($entries, [
        ['id' => 'logger', 'config' => ['level' => 'debug']],
        [
            'id' => 'stack',
            'insert' => [
                ['id' => 'metrics', 'name' => 'metrics', 'config' => ['sample' => 1]],
            ],
        ],
        [
            'insert' => [
                ['id' => 'health', 'name' => 'health'],
            ],
        ],
    ]);

    expect($patched)->toBe([
        [
            'id' => 'stack',
            'group' => [
                ['id' => 'logger', 'name' => 'logger', 'config' => ['level' => 'debug']],
                ['id' => 'metrics', 'name' => 'metrics', 'config' => ['sample' => 1]],
            ],
        ],
        ['id' => 'health', 'name' => 'health'],
    ]);
});

test('patches fail closed for missing targets and ambiguous insert overlays', function (): void {
    $entries = [['id' => 'logger', 'name' => 'logger']];

    expect(fn (): array => PatchApplicator::apply($entries, [['id' => 'missing', 'config' => []]]))
        ->toThrow(PatchException::class);
    expect(fn (): array => PatchApplicator::apply($entries, [['id' => 'logger', 'insert' => [], 'config' => []]]))
        ->toThrow(PatchException::class);
    expect(fn (): array => PatchApplicator::apply($entries, [['id' => 'logger', 'insert' => []]]))
        ->toThrow(PatchException::class);
});
