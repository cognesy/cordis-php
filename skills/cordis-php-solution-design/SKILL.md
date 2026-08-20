---
name: cordis-php-solution-design
description: >-
  Design Cordis PHP solutions from a new feature, service boundary,
  multi-tenant requirement, runtime topology, or agent-oriented workflow. Use
  when an operator or coding agent must choose plugin, service, dependency,
  event, isolation, YAML composition, and acceptance boundaries before
  implementation.
---

# Cordis PHP solution design

## Classify the workload

Choose the smallest verified Cordis shape before designing classes or YAML.

- **Resource lifecycle:** a plugin that provides a service and returns cleanup.
  Read `examples/01-scoped-lifecycle` first.
- **Replaceable provider:** a named service with declared consumers. Read
  `examples/02-dynamic-service-restart` first.
- **Configuration rollout:** a YAML entry tree and a narrow patch. Read
  `examples/03-yaml-live-reload` or `examples/08-yaml-service-swap` first.
- **Extension point:** a scoped event with an explicit dispatch mode. Read
  `examples/04-event-pipeline` first.
- **Privacy boundary:** an isolated child realm. Read
  `examples/06-tenant-isolation` first.
- **Per-workload policy:** `intercept` metadata over one shared service. Read
  `examples/09-service-interception` first.

Keep scheduling, request handling, model calls, and process supervision in the
host. Cordis PHP owns synchronous composition and lifecycle, not an async
agent loop or web server.

## Define ownership before implementation

1. Name each service by the capability it offers, not its current adapter.
   For example, use `notes`, `model-client`, or `trace-sink`, rather than a
   vendor or deployment detail.
2. Assign exactly one live provider for each service in a realm. Let consumers
   declare requirements instead of reaching into a concrete provider.
3. Put subscriptions, handles, child plugins, and external connections in the
   provider or consumer scope that owns their lifetime.
4. Decide whether a child can inherit a service. Use `isolate` to make a
   service intentionally unavailable; use `intercept` only for local metadata,
   never as a hidden replacement for the service itself.
5. Use stable YAML `id` values for deployment-owned topology. Treat an `id` as
   the reconciliation identity, not a display name.

## Produce a design record

Write these decisions before code:

1. **Capability:** the user-visible outcome and its plugin entry points.
2. **Services:** provider, consumer list, ownership scope, and replacement
   expectation for every service.
3. **Dependencies:** static `RequiresServices` requirements and any YAML
   `inject` additions. State what the consumer does while pending.
4. **Events:** event name, payload convention, owner, and dispatch mode.
   Choose `waterfall` for transforms and `bail` for first successful resolver.
5. **Configuration:** stable entry ids, plugin names, secrets as safe
   expressions, and patchable deployment differences.
6. **Failure and recovery:** expected `pending` or `failed` states, observable
   fields, rollback path, and cleanup behavior.
7. **Proof:** one focused test and one executable scenario or example outcome.

## Build a thin vertical slice

1. Register one plugin definition in `PluginRegistry`.
2. Mount it in a `Runtime` with the smallest required service contract.
3. Use `Context::provide()`, `Context::on()`, or `Context::plugin()` so work
   belongs to the correct effect scope.
4. Encode deployment topology in `Runtime::yaml()` only after the direct
   lifecycle behavior is proven.
5. Capture a state transition, service replacement, or reconciliation report
   that proves the design actually has the intended boundary.

## Preserve the design invariants

- Do not use a global mutable container to bypass service ownership.
- Do not hide required services inside arbitrary `Context::get()` calls.
- Do not encode executable PHP in YAML. Expressions are limited to `env`,
  `service`, `coalesce`, and `concat`.
- Do not use an isolation rule as an authorization system. It is a runtime
  visibility boundary; put durable authorization at the host or service edge.
- Do not call an unchanged reload a rollout. Require a `ReconcileReport` that
  shows the intended mounted, updated, disposed, or failed paths.

## Verify the design

Run the closest example first, then `composer test`, `composer examples`, and
`just ci` when the change reaches the repository. Read
`src/Runtime/Runtime.php`, `src/Runtime/Fiber.php`, and
`docs/yaml-composition.md` before changing a lifecycle rule.
