<?php

declare(strict_types=1);

use CordisPhp\Config\EntryParser;
use CordisPhp\Config\Expression;
use CordisPhp\Exception\ConfigurationException;

test('the entry parser accepts nested groups and config-only expressions', function (): void {
    $entries = (new EntryParser())->parseList([
        [
            'id' => 'platform',
            'group' => [
                [
                    'id' => 'logger',
                    'name' => 'logger',
                    'config' => [
                        'label' => ['$expr' => 'env:APP_LABEL'],
                    ],
                    'inject' => ['clock'],
                    'isolate' => ['secret'],
                    'intercept' => ['clock' => ['format' => 'iso8601']],
                ],
            ],
        ],
    ]);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->isGroup())->toBeTrue()
        ->and($entries[0]->group[0]->name)->toBe('logger')
        ->and($entries[0]->group[0]->config['label'])->toBeInstanceOf(Expression::class)
        ->and($entries[0]->group[0]->inject)->toBe(['clock'])
        ->and($entries[0]->group[0]->intercept)->toBe(['clock' => ['format' => 'iso8601']]);
});

test('the entry parser reports closed-envelope mistakes together', function (): void {
    try {
        (new EntryParser())->parseList([
            [
                'id' => '',
                'name' => 'logger',
                'unknown' => true,
                'inject' => ['clock', 'clock'],
            ],
            [
                'id' => 'logger',
                'name' => 'logger',
                'isolate' => ['$expr' => 'env:UNSAFE'],
            ],
            [
                'id' => 'container',
                'config' => ['ignored' => true],
                'group' => [],
            ],
        ]);
        throw new RuntimeException('Expected invalid YAML configuration.');
    } catch (ConfigurationException $error) {
        expect($error->getMessage())->toContain('$[0].id')
            ->toContain('$[0]: contains unsupported field "unknown"')
            ->toContain('$[1].isolate: expressions are permitted only in config and disabled')
            ->toContain('$[2].config: is not supported on a group entry');
    }
});
