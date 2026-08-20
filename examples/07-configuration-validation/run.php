<?php

declare(strict_types=1);

use CordisPhp\Contract\ConfigurablePlugin;
use CordisPhp\Contract\Plugin;
use CordisPhp\Exception\PluginException;
use CordisPhp\Plugin\PluginRegistry;
use CordisPhp\Runtime\Context;
use CordisPhp\Runtime\Runtime;

use function CordisPhp\Examples\expectSame;
use function CordisPhp\Examples\printResult;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/bootstrap.php';

final class DeliveryWorker implements Plugin, ConfigurablePlugin
{
    /** @var list<string> */
    public static array $started = [];

    public static function reset(): void
    {
        self::$started = [];
    }

    public static function validateConfig(mixed $config): mixed
    {
        $region = is_array($config) ? ($config['region'] ?? null) : null;
        $retries = is_array($config) && array_key_exists('retries', $config)
            ? $config['retries']
            : 3;
        $issues = [];

        if (! is_string($region) || $region === '') {
            $issues[] = 'region must be a non-empty string';
        }
        if (! is_int($retries) || $retries < 0) {
            $issues[] = 'retries must be a non-negative integer';
        }
        if ($issues !== []) {
            throw new PluginException('Delivery config invalid: '.implode('; ', $issues).'.');
        }

        return ['region' => $region, 'retries' => $retries];
    }

    public function apply(Context $_context, mixed $config): ?Closure
    {
        $region = is_array($config) ? ($config['region'] ?? null) : null;
        $retries = is_array($config) ? ($config['retries'] ?? null) : null;
        if (! is_string($region) || ! is_int($retries)) {
            throw new PluginException('DeliveryWorker received unvalidated config.');
        }

        self::$started[] = "$region:$retries";

        return null;
    }
}

DeliveryWorker::reset();
$plugins = new PluginRegistry();
$plugins->registerClass('delivery-worker', DeliveryWorker::class);

$runtime = new Runtime($plugins);
$accepted = $runtime->mount('delivery-worker', ['region' => 'eu-west', 'retries' => 2]);
$rejected = $runtime->mount('delivery-worker', ['region' => 9, 'retries' => 'many']);

$snapshot = [
    'accepted' => [
        'state' => $accepted->state()->value,
        'config' => $accepted->config(),
    ],
    'rejected' => [
        'state' => $rejected->state()->value,
        'error' => $rejected->error()?->getMessage(),
    ],
    'plugin_body_runs' => DeliveryWorker::$started,
];

$runtime->dispose();

expectSame([
    'accepted' => [
        'state' => 'active',
        'config' => ['region' => 'eu-west', 'retries' => 2],
    ],
    'rejected' => [
        'state' => 'failed',
        'error' => 'Delivery config invalid: region must be a non-empty string; retries must be a non-negative integer.',
    ],
    'plugin_body_runs' => ['eu-west:2'],
], $snapshot, 'Configuration must be validated before a plugin body runs');

printResult([
    'scenario' => 'configuration-validation',
    ...$snapshot,
]);
