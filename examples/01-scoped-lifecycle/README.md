# Scoped lifecycle: notification bridge

## Context

A long-running PHP worker sends notifications and listens for completion
events. It may be reconfigured or shut down while work continues.

## Problem solved

Manual cleanup usually misses one of the service registration, event listener,
or external resource. A listener that survives the plugin which created it is
both a memory leak and a source of duplicate work.

## Practical use case

Use a Cordis plugin for a notification, webhook, queue, or websocket bridge.
Its service and event subscription belong to one scope and disappear together.

## Run

```sh
php examples/01-scoped-lifecycle/run.php
```

## Expected outcome

The JSON proves that the bridge is active and receives `invoice-42`, then that
disposal removes its `notifier` service, cancels its listener, and leaves no
active fibers. The later `ignored-after-dispose` event never appears.

## APIs demonstrated

- `PluginRegistry::registerClosure()`
- `Context::provide()`
- `Context::on()`
- `Fiber::dispose()`
