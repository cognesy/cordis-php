# Cordis PHP

Cordis PHP is a strictly typed PHP 8.4+ runtime for building applications from
reversible plugins and YAML composition. It takes the durable parts of Cordis:

- plugin instances own a scope and clean up in reverse order;
- services can appear, disappear, and be replaced while consumers restart;
- a loader reconciles a YAML entry tree instead of treating configuration as a
  one-time bootstrap script;
- patches compose a base configuration with deployment or user layers; and
- expressions are structured data, never executable PHP.

It is deliberately synchronous. PHP processes usually own the event loop at a
higher layer, so a plugin's lifecycle is deterministic and can be embedded in
CLI, worker, web, or framework hosts without imposing an async runtime.

## Install

```sh
composer require cordis-php/cordis
```

## Minimal composition

```php
<?php

declare(strict_types=1);

use CordisPhp\Plugin\PluginRegistry;
use CordisPhp\Runtime\Runtime;

$plugins = new PluginRegistry();
$plugins->registerClass('clock', App\ClockPlugin::class);

$runtime = new Runtime($plugins);
$loader = $runtime->yaml(__DIR__ . '/app.yml');
$loader->reload();
```

```yaml
# app.yml
- id: clock
  name: clock
  config:
    timezone: UTC
```

Plugins implement `CordisPhp\Contract\Plugin`. They receive a child
`Context`, can provide services, subscribe to events, and return an optional
cleanup closure. Every registration is automatically tied to the plugin's
scope.

## YAML grammar

Each entry has an `id` and either a plugin `name` or a `group` of child
entries. `config` and `disabled` are the only expression-capable fields.

```yaml
- id: app
  group:
    - id: storage
      name: storage.sqlite
      config:
        path:
          $expr:
            env: APP_DB
            default: var/app.sqlite
    - id: api
      name: api.http
      inject: [storage]
```

The safe expression vocabulary is small and explicit:

- `{ $expr: { env: NAME, default: value } }`
- `{ $expr: { service: service-name } }`
- `{ $expr: { coalesce: [value-or-expression, ...] } }`
- `{ $expr: { concat: [value-or-expression, ...] } }`

No YAML value is evaluated as PHP. A `!expr` YAML tag is also accepted and
uses the same structured payload.

The runtime envelope is closed and has a CI/editor-friendly YAML Schema:
[YAML composition guide](docs/yaml-composition.md) and
[schema](resources/schema/composition.schema.yaml). Plugin `config` remains
opaque to that envelope schema and is validated by its registered plugin.

## Layering and reload

`YamlRuntimeLoader::reload($patches)` reads and validates a complete document,
applies id-targeted patches, and reconciles the live plugin tree. A patch can
replace a row's fields or append entries to the root or a group:

```yaml
- id: api
  config:
    port: 8080
- insert:
    - id: metrics
      name: telemetry.metrics
```

`reloadIfChanged()` is the host-friendly hot-reload primitive: a CLI watcher,
RoadRunner worker, or framework development server decides when to call it.

## Quality gates

```sh
composer install
composer qa
composer examples
ys -f resources/schema/composition.schema.yaml examples/03-yaml-live-reload/composition.initial.yaml
```

The Pest suite covers lifetimes, service replacement, dependency pending and
restart, event modes, YAML validation, expression safety, patches, groups,
and live reconciliation.

## Runnable examples

The [examples](examples/README.md) directory contains nine self-checking CLI
programs. They show scoped cleanup, dynamic dependency restart, YAML reloads,
event pipelines, runtime observability, service isolation, configuration
validation, deployment-owned service swaps, and local service interception
using only this library's public API.

Run all of them with:

```sh
composer examples
```
