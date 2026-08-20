---
name: cordis-php-plugin-development
description: >-
  Implement, extend, and test lifecycle-safe Cordis PHP plugins, including
  services, subscriptions, cleanup, dependencies, and plugin-owned
  configuration validation. Use when a coding agent or developer adds or
  changes a Cordis PHP capability implementation.
---

# Cordis PHP plugin development

## Choose the plugin form

Use a class when the capability has named contracts or validated configuration.
Use a closure only for a small, local adapter with a clear dependency list.

```php
final class WorkerPlugin implements Plugin, ConfigurablePlugin, RequiresServices
{
    public static function requiredServices(): array
    {
        return ['queue'];
    }

    public static function validateConfig(mixed $config): mixed
    {
        // Return normalized data or throw a domain-relevant exception.
    }

    public function apply(Context $context, mixed $config): ?Closure
    {
        $queue = $context->get('queue');
        $release = $context->provide('worker', new Worker($queue, $config));

        return $release;
    }
}
```

Register a class with `PluginRegistry::registerClass()` or a closure with
`registerClosure()`. Keep a plugin name stable for YAML composition.

## Follow the lifecycle order

1. Declare every required service with `RequiresServices` or the explicit
   `requires` argument. Let the fiber remain `pending` until they exist.
2. Validate and normalize plugin configuration with `ConfigurablePlugin`.
   Validation happens before `apply()`, so invalid settings fail before a
   plugin creates side effects.
3. Acquire dependencies through the supplied `Context`, not a global holder.
4. Register services with `Context::provide()` and listeners with
   `Context::on()`. The context scope owns their release automatically.
5. Return a cleanup closure only for external work not already scope-bound,
   such as a socket, file watch, or SDK connection.
6. Mount child capabilities with `Context::plugin()` so they are disposed with
   their parent rather than leaking into the runtime root.

## Design configuration as a contract

Keep `config` opaque to the YAML envelope. Accept an array, scalar, or object
shape deliberately; validate every field, normalize defaults, and return only
the value `apply()` expects. Put secret lookup in a safe YAML expression or
the host environment, not in executable YAML.

Use `examples/07-configuration-validation` for the failure boundary and
`tests/Support/ContractPlugin.php` for the class-contract pattern.

## Test transition behavior

Write a focused test that proves all relevant outcomes:

1. Missing dependency: `pending` with the expected `missing()` list.
2. Available dependency: `active` and exactly one application of the plugin.
3. Replacement or removal: cleanup occurs and consumers restart or return to
   `pending` as appropriate.
4. Invalid configuration: `failed`, useful `error()`, and no plugin-body side
   effect.
5. Disposal: services and subscriptions are gone, including children.

Use `tests/Runtime/FiberLifecycleTest.php` as the behavioral baseline rather
than testing internal registry fields.

## Avoid lifecycle leaks

- Do not keep a raw service-release closure without assigning its lifetime.
- Do not subscribe through the global event bus when the plugin should own the
  subscription; use `Context::on()`.
- Do not call `EffectScope::dispose()` from inside `apply()`.
- Do not catch a setup failure and return success. Let the fiber expose its
  failed state and error so a later configuration or dependency change can
  recover it.

Run `composer test`, the closest executable example, and `just ci` for an
integrated plugin change.
