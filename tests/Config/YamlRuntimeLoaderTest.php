<?php

declare(strict_types=1);

use CordisPhp\Config\ExpressionEvaluator;
use CordisPhp\Config\LoaderLimits;
use CordisPhp\Exception\ConfigurationException;
use CordisPhp\Plugin\PluginRegistry;
use CordisPhp\Runtime\Context;
use CordisPhp\Runtime\FiberState;
use CordisPhp\Runtime\Runtime;
use CordisPhp\Tests\Support\ContractPlugin;
use CordisPhp\Tests\Support\YamlFixture;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

test('YAML groups mount, retain structural expression equality, and reconcile changed config', function (): void {
    $events = [];
    $plugins = new PluginRegistry();
    $plugins->registerClosure('recorder', function (Context $_context, mixed $config) use (&$events): Closure {
        $label = is_array($config) && is_string($config['label'] ?? null) ? $config['label'] : 'invalid';
        $events[] = "start:$label";

        return function () use (&$events, $label): void {
            $events[] = "stop:$label";
        };
    });
    $runtime = new Runtime($plugins, new ExpressionEvaluator(['APP_LABEL' => 'first']));
    $path = YamlFixture::create(<<<'YAML'
- id: platform
  group:
    - id: logger
      name: recorder
      config:
        label: !expr env:APP_LABEL
    - id: metrics
      name: recorder
      config:
        label: metrics
YAML);

    try {
        $loader = $runtime->yaml($path);
        $initial = $loader->reload();
        expect($initial->mounted)->toBe(['platform', 'platform.logger', 'platform.metrics'])
            ->and($loader->live())->toBe(['platform', 'platform.logger', 'platform.metrics'])
            ->and($events)->toBe(['start:first', 'start:metrics']);

        $unchanged = $loader->reload();
        expect($unchanged->isQuiet())->toBeTrue()
            ->and($unchanged->unchanged)->toBe(['platform.logger', 'platform.metrics'])
            ->and($events)->toBe(['start:first', 'start:metrics']);

        expect($loader->reloadIfChanged())->toBeNull();

        YamlFixture::overwrite($path, <<<'YAML'
- id: platform
  group:
    - id: logger
      name: recorder
      config:
        label: second
    - id: metrics
      name: recorder
      config:
        label: metrics
YAML);

        $changed = $loader->reloadIfChanged();
        expect($changed)->not->toBeNull()
            ->and($changed->updated)->toBe(['platform.logger'])
            ->and($events)->toBe(['start:first', 'start:metrics', 'stop:first', 'start:second']);

        $loader->dispose();
        expect($events)->toBe([
            'start:first',
            'start:metrics',
            'stop:first',
            'start:second',
            'stop:second',
            'stop:metrics',
        ]);
    } finally {
        YamlFixture::remove($path);
    }
});

test('failed preflight leaves a healthy live composition in place', function (): void {
    $events = [];
    $plugins = new PluginRegistry();
    $plugins->registerClosure('recorder', function (Context $_context, mixed $_config) use (&$events): Closure {
        $events[] = 'start';

        return function () use (&$events): void {
            $events[] = 'stop';
        };
    });
    $runtime = new Runtime($plugins);
    $path = YamlFixture::create(<<<'YAML'
- id: recorder
  name: recorder
YAML);

    try {
        $loader = $runtime->yaml($path);
        $loader->reload();
        YamlFixture::overwrite($path, <<<'YAML'
- id: recorder
  name: missing-plugin
YAML);

        $report = $loader->reload();

        expect($report->failed)->toHaveCount(1)
            ->and($report->failed[0]->path)->toBe('recorder')
            ->and($loader->live())->toBe(['recorder'])
            ->and($events)->toBe(['start']);
    } finally {
        YamlFixture::remove($path);
    }
});

test('loader applies explicit patches and excludes disabled entries safely', function (): void {
    $events = [];
    $plugins = new PluginRegistry();
    $plugins->registerClosure('recorder', function (Context $_context, mixed $config) use (&$events): null {
        $events[] = is_array($config) ? ($config['label'] ?? 'none') : 'none';

        return null;
    });
    $runtime = new Runtime($plugins, new ExpressionEvaluator(['DISABLED' => true]));
    $path = YamlFixture::create(<<<'YAML'
- id: item
  name: recorder
  config:
    label: base
- id: hidden
  name: recorder
  disabled: !expr env:DISABLED
YAML);

    try {
        $loader = $runtime->yaml($path);
        $report = $loader->reload([
            ['id' => 'item', 'config' => ['label' => 'patched']],
        ]);

        expect($report->mounted)->toBe(['item'])
            ->and($loader->live())->toBe(['item'])
            ->and($events)->toBe(['patched']);
    } finally {
        YamlFixture::remove($path);
    }
});

test('a failed plugin configuration can be corrected by a later YAML reload', function (): void {
    ContractPlugin::reset();
    $plugins = new PluginRegistry();
    $plugins->registerClass('contract', ContractPlugin::class);
    $runtime = new Runtime($plugins);
    $runtime->root()->provide('clock', '12:00');
    $path = YamlFixture::create(<<<'YAML'
- id: contract
  name: contract
  config: {}
YAML);

    try {
        $loader = $runtime->yaml($path);
        $failed = $loader->reload();
        expect($failed->failed)->toHaveCount(1)
            ->and($runtime->fibers()[0]->state())->toBe(FiberState::Failed);

        YamlFixture::overwrite($path, <<<'YAML'
- id: contract
  name: contract
  config:
    message: recovered
YAML);
        $recovered = $loader->reload();

        expect($recovered->updated)->toBe(['contract'])
            ->and($runtime->fibers()[0]->state())->toBe(FiberState::Active)
            ->and(ContractPlugin::$applied)->toBe([
                ['clock' => '12:00', 'config' => ['message' => 'recovered']],
            ]);
    } finally {
        YamlFixture::remove($path);
    }
});

test('invalid YAML document structure fails before mounting anything', function (): void {
    $runtime = new Runtime();
    $path = YamlFixture::create("id: not-an-entry-list\n");

    try {
        expect(fn () => $runtime->yaml($path)->reload())
            ->toThrow(ConfigurationException::class, 'entry list');
    } finally {
        YamlFixture::remove($path);
    }
});

test('loader limits reject invalid reloads before lifecycle changes', function (LoaderLimits $limits, string $replacement, string $breach): void {
    $events = [];
    $plugins = new PluginRegistry();
    $plugins->registerClosure('recorder', function (Context $_context, mixed $_config) use (&$events): Closure {
        $events[] = 'start';

        return function () use (&$events): void {
            $events[] = 'stop';
        };
    });
    $runtime = new Runtime($plugins);
    $path = YamlFixture::create(<<<'YAML'
- id: recorder
  name: recorder
YAML);

    try {
        $loader = $runtime->yaml($path, limits: $limits);
        $loader->reload();
        YamlFixture::overwrite($path, $replacement);

        try {
            $loader->reload();
            throw new RuntimeException('Expected the YAML loader to reject the replacement.');
        } catch (ConfigurationException $error) {
            expect($error->getMessage())->toContain($path)->toContain($breach);
        }

        expect($loader->live())->toBe(['recorder'])
            ->and($events)->toBe(['start']);
    } finally {
        YamlFixture::remove($path);
    }
})->with([
    'oversized source' => [
        new LoaderLimits(maxBytes: 64),
        str_repeat("# padding\n", 8),
        'maximum size of 64 bytes',
    ],
    'too many entries' => [
        new LoaderLimits(maxEntries: 1),
        <<<'YAML'
- id: replacement
  name: recorder
- id: another
  name: recorder
YAML,
        'maximum entry count of 1',
    ],
    'too deeply nested groups' => [
        new LoaderLimits(maxNesting: 3),
        <<<'YAML'
- id: replacement
  group:
    - id: nested
      name: recorder
YAML,
        'maximum nesting depth of 3',
    ],
    'too deeply nested config' => [
        new LoaderLimits(maxNesting: 3),
        <<<'YAML'
- id: replacement
  name: recorder
  config:
    first:
      second: true
YAML,
        'maximum nesting depth of 3',
    ],
]);

test('Symfony YAML aliases are materialized as independent values', function (): void {
    $raw = Yaml::parse(<<<'YAML'
defaults: &defaults
  nested: original
left: *defaults
right: *defaults
YAML);
    $raw['left']['nested'] = 'changed';

    expect($raw['defaults']['nested'])->toBe('original')
        ->and($raw['right']['nested'])->toBe('original');
});

test('Symfony YAML rejects recursive aliases before traversal', function (): void {
    expect(fn (): mixed => Yaml::parse(<<<'YAML'
root: &shared
  left:
    nested: original
  right: *shared
YAML))
        ->toThrow(ParseException::class, 'Circular reference');
});
