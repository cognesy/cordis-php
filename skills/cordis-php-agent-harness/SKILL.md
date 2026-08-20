---
name: cordis-php-agent-harness
description: >-
  Design and evolve Cordis PHP agent harnesses with replaceable model and tool
  providers, scoped policies, sessions, telemetry, and safe recovery. Use
  when an operator or coding agent is building an LLM workflow, tool runner,
  prompt policy pipeline, or multi-agent application on Cordis PHP.
---

# Cordis PHP agent harness

## Keep the agent loop host-owned

Use Cordis PHP to compose and restart agent capabilities. Keep token streaming,
request concurrency, model transports, queues, and supervisor behavior in the
host application. The runtime is synchronous and deliberately does not create
a scheduler.

## Model the harness as capabilities

Use service contracts rather than adapter imports:

- **Model provider (`model-client`):** the agent worker remains pending until it
  is available and restarts on rotation.
- **Tool catalogue (`tool-catalog`):** a resolver uses the current catalogue
  without owning it.
- **Policy pipeline (`prompt.prepare`, for example):** handlers transform or
  reject request-local input.
- **Session persistence (`session-store`):** a session worker depends on the
  store contract.
- **Telemetry (`trace-sink`):** workers emit lifecycle and request evidence
  without owning exporters.

Register concrete adapters at the application edge. Make a worker declare its
requirements through `RequiresServices` or YAML `inject`; do not let a worker
assume a model client exists.

## Choose a policy mechanism deliberately

1. Use `EventBus::waterfall()` to transform a prompt through ordered policy
   stages such as redaction and approval.
2. Use `EventBus::bail()` to resolve one tool or policy result from several
   candidates.
3. Use `serial()` or `parallel()` only for independent result collection.
   Both execute synchronously in PHP; `parallel()` is not a background task
   scheduler.
4. Register listeners through `Context::on()` so session- or worker-owned
   handlers disappear with their owner.

Start with `examples/04-event-pipeline` and give each event a typed-by-
convention payload contract in application code.

## Handle replacement and tenancy

1. Provide a model, tool, or store service from the plugin that owns its
   cleanup. Releasing or replacing the provider triggers a dependency settle.
2. Expect a dependent agent fiber to move through `active`, `unloading`, and
   `pending` before starting against the new binding. Prove this with the
   pattern in `examples/02-dynamic-service-restart`.
3. Use `isolate` to hide a root-only credential from a tenant or delegated
   worker. A hidden required service should be visible as `pending` with an
   explicit missing service, as in `examples/06-tenant-isolation`.
4. Use `intercept` metadata for per-agent timeout, retry, route, or budget
   policy while sharing one client. Read it through
   `Context::interceptionFor('model-client')`; do not mutate client defaults.

## Build the first executable slice

1. Register a model-provider plugin and one agent-worker plugin.
2. Declare `model-client` as the worker requirement.
3. Add a scoped `prompt.prepare` waterfall and one `tool.resolve` bail point.
4. Mount the composition, then replace the provider in a test or example.
5. Capture worker state, missing dependencies, status changes, and request
   policy metadata in structured logs at the host edge.

## Prove safe behavior

- Prove a missing provider leaves a worker pending without partial side effects.
- Prove provider removal releases consumers and replacement restarts them.
- Prove a policy listener is removed when its fiber is disposed.
- Prove tenant isolation cannot access a deliberately hidden service.
- Run the focused test, the closest example, `composer examples`, and
  `just ci` before treating a harness change as integrated.
