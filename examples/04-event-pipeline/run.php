<?php

declare(strict_types=1);

use CordisPhp\Plugin\PluginRegistry;
use CordisPhp\Runtime\Context;
use CordisPhp\Runtime\Runtime;

use function CordisPhp\Examples\expectSame;
use function CordisPhp\Examples\printResult;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/bootstrap.php';

$plugins = new PluginRegistry();
$plugins->registerClosure('prompt-redactor', static function (Context $context, mixed $_config): null {
    $context->on(
        'prompt.prepare',
        static fn (string $prompt): string => str_replace('secret', '[redacted]', $prompt),
    );

    return null;
});
$plugins->registerClosure('policy-suffix', static function (Context $context, mixed $_config): null {
    $context->on(
        'prompt.prepare',
        static fn (string $prompt): string => "$prompt | policy-approved",
    );

    return null;
});
$plugins->registerClosure('weather-tool', static function (Context $context, mixed $_config): null {
    $context->on(
        'tool.resolve',
        static fn (string $name): ?string => $name === 'weather' ? 'weather-tool-v1' : null,
    );

    return null;
});

$runtime = new Runtime($plugins);
$redactor = $runtime->mount('prompt-redactor');
$runtime->mount('policy-suffix');
$runtime->mount('weather-tool');

$preparedWithRedactor = $runtime->events()->waterfall('prompt.prepare', 'summarize secret');
$resolvedTool = $runtime->events()->bail('tool.resolve', 'weather');
$missingTool = $runtime->events()->bail('tool.resolve', 'calendar');

$redactor->dispose();
$preparedWithoutRedactor = $runtime->events()->waterfall('prompt.prepare', 'summarize secret');
$runtime->dispose();

expectSame('summarize [redacted] | policy-approved', $preparedWithRedactor, 'The policy pipeline must transform in registration order');
expectSame('weather-tool-v1', $resolvedTool, 'The first matching tool must resolve the request');
expectSame(null, $missingTool, 'An unknown tool must not invent a result');
expectSame('summarize secret | policy-approved', $preparedWithoutRedactor, 'Disposing a plugin must remove its listener');

printResult([
    'scenario' => 'event-pipeline',
    'prepared_with_redactor' => $preparedWithRedactor,
    'resolved_tool' => $resolvedTool,
    'missing_tool' => $missingTool,
    'prepared_without_redactor' => $preparedWithoutRedactor,
]);
