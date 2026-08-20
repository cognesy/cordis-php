# YAML service swap: deployment-owned note storage

## Context

An application records notes through a `notes` service while its deployment
chooses the storage implementation. Local development starts in memory; a
deployment layer switches the same capability to a file-backed store.

## Problem solved

Directly importing a storage provider leaks an infrastructure choice into
consumers. Editing a base composition for every environment also makes a
small rollout hard to review. This example keeps consumers bound to the
service contract and applies a narrow, checked YAML overlay to the provider.

## Practical use case

Use this shape to change cache, storage, transport, or credential-provider
settings by deployment without teaching business plugins how the provider is
implemented.

## Run

```sh
php examples/08-yaml-service-swap/run.php
```

The script creates its file-backed store in the system temporary directory and
removes it before exiting.

## Expected outcome

The initial composition starts a memory store, writer, and reporter. The
`swap.yaml` overlay changes only the store configuration; Cordis releases the
old service, restarts dependent consumers, and writes the same note through
the file-backed implementation.

## APIs demonstrated

- `YamlRuntimeLoader::reload()` with id-targeted patches
- `PatchApplicator`'s guarded `name` precondition
- YAML `!expr env:...` inside an overlay
- scoped service replacement and automatic dependency restart
