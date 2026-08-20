# Runtime observability: health and telemetry adapter

## Context

A long-running agent host needs to distinguish a healthy capability from one
waiting on a dependency or failing during startup. Logs alone cannot provide a
reliable control-plane view.

## Problem solved

Without lifecycle signals, operators can only infer why a plugin is unavailable
from side effects. A failed exporter may otherwise look the same as one that
was never configured.

## Practical use case

Subscribe to `Runtime::onStatus()` once from a PSR-3, OpenTelemetry, or metrics
adapter. Build a health endpoint from `Runtime::fibers()`. This example shows an
active worker and a failed trace exporter, then proves that shutdown removes
both from the snapshot.

## Run

```sh
php examples/05-runtime-observability/run.php
```

## Expected outcome

The JSON exposes the active and failed fibers with their state, missing
dependencies, and failure message. Its transition list can be sent directly to
a counter, event log, or tracing adapter without instrumentation in each
plugin.

## APIs demonstrated

- `Runtime::onStatus()` and `FiberStatusChange`
- `Runtime::fibers()` health snapshots
- `Fiber::state()`, `Fiber::missing()`, and `Fiber::error()`
- safe shutdown of failed and active fibers
