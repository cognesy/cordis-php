# YAML live reload: safe model-client rollout

## Context

A worker deploys an HTTP client configuration from YAML. It needs environment
values, a runtime service value, and a way to change only the affected client.

## Problem solved

Static configuration encourages whole-process restarts and lets arbitrary
configuration mistakes affect unrelated capabilities. Code-based configuration
often makes deploy diffs hard to review.

## Practical use case

This composition starts a logger and an HTTP client. Its URL is built from a
safe environment expression plus the `api-host` service. A changed YAML file
updates only the HTTP client from `/v1` to `/v2`; the logger is left running.

## Run

```sh
php examples/03-yaml-live-reload/run.php
```

## Expected outcome

The script copies the static YAML files to a temporary location, so it never
edits the repository. The JSON reports the initial three mounted entries, a
quiet unchanged reload, then a single `platform.client` update. Its event list
shows `v1` stopping before `v2` starts.

## APIs demonstrated

- `Runtime::yaml()` and `YamlRuntimeLoader::reloadIfChanged()`
- `!expr env:...`, `service:...`, and `concat` expressions
- structural reconciliation and `ReconcileReport`
- safe teardown of a removed client instance
