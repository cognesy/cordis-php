---
name: cordis-php-runtime-refactoring
description: >-
  Refactor a Cordis PHP solution or runtime boundary while preserving service
  ownership, cleanup order, dependency restart, YAML reconciliation, and
  observable behavior. Use when a coding agent or maintainer restructures
  plugins, services, configuration, events, or lifecycle code in an existing
  Cordis PHP application.
---

# Cordis PHP runtime refactoring

## Characterize the live contract first

Map the change before moving code:

1. Find every provider and consumer of the affected service name.
2. Record the fiber owner, required services, isolation rules, interception
   metadata, child fibers, subscriptions, and cleanup resources.
3. Record the expected state sequence for initial mount, provider removal,
   replacement, configuration change, and disposal.
4. Record the expected YAML identity and report paths if composition controls
   the topology.
5. Add or preserve a focused test that captures the current contract before
   altering implementation structure.

Use `src/Runtime/Fiber.php`, `src/Runtime/Context.php`, and
`tests/Runtime/FiberLifecycleTest.php` as the lifecycle truth, not an inferred
call graph.

## Choose the refactoring boundary

- **Extract a plugin class:** preserve plugin name, requirements, normalized
  config, and cleanup. Test pending-to-active and disposal.
- **Rename a service:** preserve the provider/consumer contract and migration
  path. Test a missing dependency and replacement.
- **Change a provider adapter:** preserve the capability service name and
  consumer behavior. Test old release, then new start.
- **Move topology to YAML:** preserve stable ids and entry shape. Test the
  reconciliation report and live paths.
- **Split a parent plugin:** preserve child ownership and reverse cleanup order.
  Test that parent disposal disposes children.
- **Introduce policy metadata:** preserve shared service identity. Test separate
  interception values per workload.

Do not use a service rename as a text-only change. Stage compatible providers
or consumers deliberately and prove the old binding is released exactly once.

## Preserve ownership and restart semantics

1. Keep services and subscriptions registered through the owning `Context`.
2. Keep external cleanup closures in the same scope as the resource they
   release.
3. Keep static dependencies in `RequiresServices`; use YAML `inject` for
   deployment-specific additions.
4. Expect an active consumer to unload and restart when a required binding
   version changes. Do not replace that behavior with manual mutation of a
   consumer's internal state.
5. Keep `Context::plugin()` for child work. Extending a context preserves parent
   ownership; mounting at the runtime root does not.

## Refactor configuration without losing rollout safety

1. Keep entry `id` stable when the desired outcome is an in-place update.
2. Recognize that changing a `name`, group structure, requirements, isolation,
   or interception changes entry shape and causes dispose-and-mount behavior.
3. Make plugin configuration changes backward-compatible or provide a guarded
   YAML patch with an explicit rollout plan.
4. Test malformed YAML, missing plugin names, invalid config, and patch target
   errors alongside the happy path.
5. Preserve a healthy composition when the candidate cannot pass parsing or
   plugin preflight.

## Use a safe sequence

1. Add a characterization test and run it before editing.
2. Make one ownership or contract movement at a time.
3. Run focused tests after each movement; inspect fiber state, missing
   dependencies, error, cleanup log, and `ReconcileReport`, not only output.
4. Run `composer test`, `composer examples`, and `just ci` after the final
   behavior is assembled.
5. Document changed service, event, or YAML contracts in the application
   boundary before deleting compatibility support.

## Refuse misleading simplifications

- Do not replace scoped lifetimes with a singleton because it shortens code.
- Do not call service registry internals to force a transition.
- Do not weaken the YAML envelope to make an old fixture parse.
- Do not suppress a cleanup failure; a recoverable `failed` fiber is more
  useful than an unloading leak.
