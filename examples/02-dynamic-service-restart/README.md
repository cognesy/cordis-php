# Dynamic service restart: rotating model endpoint

## Context

An agent worker consumes a model gateway supplied by deployment configuration or
a credential rotation process. The gateway may disappear briefly and return at
a new endpoint.

## Problem solved

A client that captures an old dependency can keep sending traffic to a stale
endpoint. Hand-written restart logic is easy to forget during rotation.

## Practical use case

Declare `model-endpoint` as a requirement of a model client. Cordis holds the
client pending until it exists, tears it down when it disappears, and starts a
new instance when the replacement arrives.

## Run

```sh
php examples/02-dynamic-service-restart/run.php
```

## Expected outcome

The JSON walks through `pending → active → pending → active`. Its event list
proves that `primary` disconnects before `secondary` connects, and that the
last client closes during runtime shutdown.

## APIs demonstrated

- `PluginDefinition` requirements via `registerClosure(..., ['model-endpoint'])`
- `Context::get()`
- scoped `Context::provide()` handles
- dependency-sensitive fiber restart
