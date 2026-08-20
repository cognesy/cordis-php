---
name: cordis-php-solution-verification
description: >-
  Verify Cordis PHP solution behavior across plugin lifecycle, dependencies,
  YAML configuration, patches, reloads, observability, and package-quality
  gates. Use when a coding agent or maintainer needs confidence that a Cordis
  PHP application change is correct, recoverable, and ready to hand off.
---

# Cordis PHP solution verification

## Choose proof by behavior

- **New provider or consumer:** prove pending state, missing service, active
  start, and scoped cleanup.
- **Service rotation:** prove old consumer cleanup, then a pending or restart,
  then a new active consumer.
- **Plugin config:** prove invalid config does not run `apply()` and normalized
  config reaches it exactly once.
- **Child plugin:** prove parent disposal releases the child and its
  registrations.
- **YAML topology:** prove a schema-valid entry list, stable ids, and expected
  live paths.
- **YAML reload:** prove the `ReconcileReport` names intended mounted, updated,
  disposed, unchanged, and failed paths.
- **Patch:** prove a wrong target or name precondition is rejected and the
  intended narrow change succeeds.
- **Isolation:** prove a hidden service produces explicit pending diagnostics.
- **Interception:** prove shared service identity with distinct immutable local
  metadata.
- **Operations:** prove status transitions and an empty runtime inventory after
  clean shutdown.

Test transitions, not just final values. A consumer that ends active but leaks
its first connection after a replacement is not correct.

## Build a focused test

1. Construct a `PluginRegistry` and `Runtime` with only the required services.
2. Capture observable starts, stops, events, status changes, and configuration
   payloads in test-owned data.
3. Assert initial state before satisfying dependencies.
4. Perform one transition: provide, release, replace, update, reload, or
   dispose.
5. Assert the new state and exact observable effect order.
6. Assert no leaked fiber, service, or event listener remains after disposal.

Follow `tests/Runtime/FiberLifecycleTest.php`,
`tests/Config/YamlRuntimeLoaderTest.php`, and
`tests/Event/EventBusTest.php` rather than asserting private implementation
details.

## Validate composition at three layers

1. Run the static YAML schema for editor and CI feedback:

   ```sh
   ys -f resources/schema/composition.schema.yaml path/to/composition.yaml
   ```

2. Run `just ops spec schema` for the repository's valid and invalid fixtures.
3. Run a runtime reload against the registered plugin catalogue and inspect the
   resulting report and live entries.

Do not use a schema-only pass as evidence that plugin-owned config is valid.
Do not use a passing runtime without a schema check as evidence that the
envelope is well-formed for editors and CI.

## Exercise executable scenarios

Start from the closest existing example and retain its assertion style:

```sh
php examples/02-dynamic-service-restart/run.php
php examples/03-yaml-live-reload/run.php
php examples/05-runtime-observability/run.php
```

Add or extend an example when a new production-facing interaction cannot be
expressed by a focused unit test alone. Keep it deterministic, self-cleaning,
and JSON-readable.

## Run the delivery floor

Run checks in increasing scope:

```sh
composer test
composer examples
just validate
just check
just ci
```

Use the repository-facing package and release procedures only when those
boundaries change or a release is explicitly authorized. Preserve the final
evidence: command output, changed composition fixtures, expected state
transitions, and any known limitations.
