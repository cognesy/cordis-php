---
name: cordis-php-composition-configuration
description: >-
  Configure and evolve Cordis PHP YAML compositions, expressions, deployment
  patches, reloads, isolation, and service interception safely. Use when an
  operator or coding agent changes a composition file, rollout layer, runtime
  topology, or environment-specific Cordis PHP configuration.
---

# Cordis PHP composition configuration

## Treat YAML as a closed runtime envelope

Read `docs/yaml-composition.md` and
`resources/schema/composition.schema.yaml` before editing a composition.

1. Make the document root a list of entries.
2. Give each sibling a stable, non-empty `id`.
3. Choose exactly one shape: a plugin entry with `name`, or a group entry with
   `group`. Do not put plugin `config` on a group.
4. Keep plugin-specific data under `config`; the registered plugin validates
   and normalizes it.
5. Use `inject`, `isolate`, and `intercept` only as explicit lifecycle and
   visibility metadata.

Validate the static envelope early:

```sh
ys -f resources/schema/composition.schema.yaml path/to/composition.yaml
just ops spec schema
```

## Use expressions narrowly

Use `!expr` or `$expr` only inside `config`, or as the entire `disabled`
value. The vocabulary is intentionally limited to `env`, `service`,
`coalesce`, and `concat`.

Do not put expressions in `id`, `name`, `inject`, `isolate`, or `intercept`.
Do not put executable PHP or arbitrary call syntax in YAML.

## Make deployment changes as guarded patches

Use a base composition for application topology and pass id-targeted patches to
`YamlRuntimeLoader::reload()` for a small deployment difference.

```php
$report = $loader->reload([
    ['id' => 'note-store', 'name' => 'note-store', 'config' => ['driver' => 'file']],
]);
```

Include `name` in a provider-swap patch as a precondition when it guards the
expected base. Use `insert` only to add children to a group or root; do not mix
it with an overlay. Read `examples/08-yaml-service-swap` before a provider
change.

## Roll out and reconcile

1. Validate the candidate YAML and the plugin registry before changing a live
   process.
2. Call `reload()` and record the `ReconcileReport` fields: `mounted`,
   `updated`, `disposed`, `unchanged`, and `failed`.
3. Expect a config change to restart its leaf fiber. Expect a shape change to
   dispose and mount the affected entry.
4. Use `reloadIfChanged()` for a host-controlled watcher. It returns `null`
   when the file revision is unchanged; it does not create a watcher.
5. Preserve the prior healthy tree on parse or unresolved-plugin preflight
   failure. Inspect `failed` rather than assuming a partial rollout occurred.

## Model access and per-workload policy

- Use `inject` for explicit requirements beyond a plugin definition.
- Use `isolate` to hide inherited services from a child realm. A hidden
  requirement produces a visible pending state.
- Use `intercept` for immutable child-local metadata such as timeout, retry,
  or routing policy. Read it through `Context::interceptionFor()` while the
  actual service remains shared.

## Prove the change

Run the closest configuration example, `composer test`, `composer examples`,
and `just ci`. For schema-level changes, also use the repository-facing
`cordis-php-composition-contracts` procedure under `ops/spec/skills`.
