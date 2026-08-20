# Configuration validation: delivery worker

## Context

A delivery worker starts from deployment-owned configuration. Its region and
retry policy must be valid before it opens a connection or begins background
work.

## Problem solved

Untyped configuration fails after a plugin has already created partial state.
`ConfigurablePlugin` makes validation and normalization a lifecycle boundary:
an invalid fiber fails visibly and `apply()` never runs.

## Practical use case

Use this for worker settings, webhook credentials, import jobs, or any plugin
whose operational inputs must be accepted or rejected before side effects.

## Run

```sh
php examples/07-configuration-validation/run.php
```

## Expected outcome

The JSON shows one active worker with normalized configuration, one failed
worker whose error names both invalid fields, and exactly one plugin-body run.

## APIs demonstrated

- `ConfigurablePlugin::validateConfig()`
- class registration through `PluginRegistry::registerClass()`
- `Fiber::state()`, `Fiber::error()`, and `Fiber::config()`
