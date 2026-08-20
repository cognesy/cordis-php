<?php

declare(strict_types=1);

namespace CordisPhp\Tests\Support;

use Closure;
use CordisPhp\Contract\ConfigurablePlugin;
use CordisPhp\Contract\Plugin;
use CordisPhp\Contract\RequiresServices;
use CordisPhp\Exception\PluginException;
use CordisPhp\Runtime\Context;

final class ContractPlugin implements Plugin, ConfigurablePlugin, RequiresServices
{
    /** @var list<array{clock: string, config: array{message: string}}> */
    public static array $applied = [];

    public static function reset(): void
    {
        self::$applied = [];
    }

    public static function requiredServices(): array
    {
        return ['clock'];
    }

    public static function validateConfig(mixed $config): mixed
    {
        if (! is_array($config) || ! is_string($config['message'] ?? null)) {
            throw new PluginException('ContractPlugin requires config.message.');
        }

        return ['message' => $config['message']];
    }

    public function apply(Context $context, mixed $config): ?Closure
    {
        if (! is_array($config) || ! is_string($config['message'] ?? null)) {
            throw new PluginException('ContractPlugin received invalid config.');
        }

        $clock = $context->get('clock');
        if (! is_string($clock)) {
            throw new PluginException('ContractPlugin requires a string clock.');
        }

        self::$applied[] = ['clock' => $clock, 'config' => ['message' => $config['message']]];

        return null;
    }
}
