---
name: cordis-php-runtime-operations
description: >-
  Operate and observe Cordis PHP runtimes through lifecycle status changes,
  fiber snapshots, YAML reconciliation reports, safe reloads, and host-owned
  telemetry. Use when an operator or coding agent needs a runbook, health
  signal, reload policy, or production-facing observability design.
---

# Cordis PHP runtime operations

## Instrument the runtime at the host edge

Install a `Runtime::onStatus()` listener when the host creates the runtime.
Record the fiber label, previous state, current state, and correlation data
owned by the host. Build a snapshot from `Runtime::fibers()` and each fiber's
`label()`, `state()`, `missing()`, and `error()`.

Treat `FiberStatusChange` as an event about lifecycle, not as an application
telemetry substitute. Emit application request metrics, model metrics, and
business audit events from their respective service boundaries.

## Use a truthful health model

- **`active`:** plugin setup completed with its requirements. Check
  application-specific health separately.
- **`pending`:** a required service is unavailable or intentionally isolated.
  Inspect `missing()` and provider topology.
- **`failed`:** startup, validation, or cleanup raised an error. Capture
  `error()`, classify it, and apply a corrected input or provider.
- **`unloading`:** restart or disposal is releasing scoped effects. Avoid
  mounting children from this context.
- **`disposed`:** its lifetime completed. Remove it from active health
  inventory.

Do not collapse `pending` and `failed` into one red status: the former is a
declarative dependency condition and can settle automatically; the latter has
an error that needs evidence.

## Operate YAML reloads deliberately

1. Validate the candidate envelope before the host requests a reload.
2. Call `reload()` from a host-controlled command, watcher, or deployment step.
3. Log the full `ReconcileReport`: mounted, updated, disposed, unchanged, and
   failed paths.
4. Use `reloadIfChanged()` only when the host already polls or receives a file
   change signal; it returns `null` for the same content hash.
5. Treat unresolved-plugin preflight or parser failure as a failed candidate.
   Preserve and observe the prior healthy tree rather than assuming a partial
   rollout.

Read `examples/03-yaml-live-reload` and `examples/08-yaml-service-swap` before
designing an operation that changes live composition.

## Build observability around owner boundaries

1. Correlate service provider start, consumer restart, and cleanup events by
   fiber label and host request or deployment identifier.
2. Add an application plugin for a telemetry exporter; let its cleanup close
   the exporter and let its own failure be visible as a fiber failure.
3. Keep tenant or workload identifiers in host metadata or plugin config; do
   not rely on a global mutable context.
4. Record local interception metadata only when it is safe to expose. It can
   explain timeout or retry behavior without exporting a credential.
5. Run a clean shutdown through `Runtime::dispose()` and collect any aggregate
   `DisposalException` instead of exiting a long-lived host blindly.

Use `examples/05-runtime-observability` as the minimum structured health
outcome: a snapshot before shutdown, ordered status transitions, and an empty
inventory after shutdown.

## Establish an operating runbook

For every critical composition, document:

1. Runtime construction and plugin registry version.
2. Composition path, patch source, revision, and intended services.
3. Expected active fibers and permitted pending fibers.
4. Status transition and reconciliation-report fields to alert on.
5. Recovery action for a missing provider, failed config, rejected reload, and
   cleanup failure.
6. Validation commands: the focused example or app smoke, `composer test`,
   `composer examples`, and `just ci` for repository changes.
