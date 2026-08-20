# Service interception: per-workload HTTP policy

## Context

One application-owned HTTP client shares its connection pool across workloads.
A bulk export needs a longer timeout, while a payment webhook must fail fast.

## Problem solved

Creating a client for every workload duplicates pools. Mutating global client
settings risks one workload changing another's request. Interception gives a
child plugin immutable, local metadata while it keeps using the shared service.

## Practical use case

Use this for timeouts, retries, feature switches, routing policy, or resource
budgets that vary by request, tenant, or plugin without changing provider
ownership.

## Run

```sh
php examples/09-service-interception/run.php
```

## Expected outcome

The JSON reports two effective policies and confirms that both workloads used
the same HTTP client object. The provider's defaults remain unchanged.

## APIs demonstrated

- YAML `intercept` mappings
- `Context::interceptionFor()`
- `Runtime::yaml()` and `YamlRuntimeLoader::reload()`
- one scoped service with multiple local policies
