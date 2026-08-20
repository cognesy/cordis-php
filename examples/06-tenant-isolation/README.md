# Tenant isolation: protect privileged credentials

## Context

A shared agent host offers common services such as logging, while privileged
credentials must not be visible to every tenant capability.

## Problem solved

Using one unrestricted global container means a plugin can accidentally obtain
a sensitive service simply because another part of the application registered
it. Convention alone is not a boundary.

## Practical use case

The root provides a shared logger and a root-only billing token. A tenant worker
can still use the logger, while a billing adapter mounted in an isolated realm
remains pending because `billing-token` is deliberately hidden.

## Run

```sh
php examples/06-tenant-isolation/run.php
```

## Expected outcome

The JSON shows the worker active with the shared logger. The isolated billing
adapter is pending and explicitly reports `billing-token` as missing, even
though the root runtime has a binding with that name.

## APIs demonstrated

- `Runtime::mount(..., isolate: ['service-id'])`
- service-realm hiding without mutating the root registry
- pending-state diagnostics for intentional isolation
