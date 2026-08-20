---
name: cordis-php-incident-troubleshooting
description: >-
  Diagnose and recover Cordis PHP pending dependencies, failed plugins,
  cleanup errors, YAML reload failures, service conflicts, isolation mistakes,
  and policy metadata issues. Use when an operator or coding agent investigates
  unexpected lifecycle state or an unhealthy Cordis PHP solution.
---

# Cordis PHP incident troubleshooting

## Capture evidence before changing state

Collect a host-side snapshot of every relevant fiber:

1. `label()`, `state()`, `requires()`, `missing()`, and `error()`.
2. Recent `Runtime::onStatus()` transitions for the same fiber.
3. Current YAML revision, applied patch input, and `ReconcileReport` fields.
4. Provider and consumer topology for each missing or replaced service.
5. Application logs around external connection setup and cleanup.

Never repair an incident by mutating `ServiceRegistry` internals or forcing a
fiber state. Correct the provider, declared dependency, configuration, or
composition and let the runtime settle.

## Follow the state-led decision path

### Fiber is `pending`

1. Read `missing()` rather than guessing.
2. Check the provider plugin registered and provided the same service name.
3. Check `RequiresServices`, YAML `inject`, and the selected child realm.
4. Check whether `isolate` intentionally hides the service.
5. Restore or provide the dependency and verify the fiber becomes `active`.

Use `examples/02-dynamic-service-restart` for provider rotation and
`examples/06-tenant-isolation` for deliberate absence.

### Fiber is `failed`

1. Capture the original `error()` and classification: configuration,
   expression, plugin startup, external adapter, or disposal.
2. For configuration, validate the YAML envelope and the plugin's normalized
   inputs. `ConfigurablePlugin` failures occur before `apply()` should create
   side effects.
3. For a dependency service expression, confirm the required service is
   available in the fiber's realm.
4. For cleanup failure, confirm all external resources are idempotently
   released; a later corrected update may start a recoverable fiber again.
5. Apply the smallest corrected configuration or provider change and observe a
   new transition to `active`.

Use `examples/07-configuration-validation` and the cleanup-recovery test in
`tests/Runtime/FiberLifecycleTest.php` as the expected diagnostic behavior.

### YAML reload reports failure

1. Check the parse and closed-envelope errors first.
2. Check that every requested plugin name is registered before reconciliation.
3. Check patch target `id`, optional `name` precondition, and insert target.
4. Inspect the report path and reason rather than the root file name alone.
5. Verify the loader's `live()` paths still represent the prior healthy tree
   after parser or unresolved-plugin preflight failure.

### Service conflict or wrong behavior

1. Find duplicate providers in the same realm; one live provider owns a
   service name.
2. Check whether a child should use a hidden, inherited, or locally provided
   binding.
3. Check `intercept` metadata with `Context::interceptionFor()` when workloads
   share a client but display different policy behavior.
4. Check event dispatch mode and listener lifetime for policy or tool-routing
   faults.

## Verify recovery

1. Reproduce the smallest incident in a focused test or temporary composition.
2. Capture the failing state and error before the corrective change.
3. Prove the corrected run becomes active, cleanup remains ordered, and no
   unrelated fiber changed unexpectedly.
4. Run the closest executable example, `composer test`, and `just ci` for a
   repository change.

Do not declare recovery from a log line alone. Require the intended fiber
state, service behavior, and reload or lifecycle evidence.
