# Cordis PHP examples

Each directory is a self-contained, deterministic CLI program. A runner
asserts its outcome before printing JSON, so the output is both documentation
and an executable contract.

```sh
composer examples
```

| Example | Practical scenario | Cordis capability |
| --- | --- | --- |
| [01: Scoped lifecycle](01-scoped-lifecycle/README.md) | Notification bridge teardown | Scoped services, listeners, cleanup |
| [02: Dynamic service restart](02-dynamic-service-restart/README.md) | Rotating model endpoint | Pending dependencies and automatic restart |
| [03: YAML live reload](03-yaml-live-reload/README.md) | Safe configuration rollout | YAML expressions and reconciliation |
| [04: Event pipeline](04-event-pipeline/README.md) | Prompt policy and tool resolution | Scoped extension points and dispatch modes |
| [05: Runtime observability](05-runtime-observability/README.md) | Health and telemetry adapter | Lifecycle transitions and snapshots |
| [06: Tenant isolation](06-tenant-isolation/README.md) | Protecting privileged credentials | Isolated service realms |
| [07: Configuration validation](07-configuration-validation/README.md) | Rejecting unsafe delivery settings | Plugin-owned validation and failure state |
| [08: YAML service swap](08-yaml-service-swap/README.md) | Deployment-owned note storage | Guarded patches and service replacement |
| [09: Service interception](09-service-interception/README.md) | Per-workload HTTP policy | Local service metadata in YAML |

Run one example directly from the repository root:

```sh
php examples/03-yaml-live-reload/run.php
```
