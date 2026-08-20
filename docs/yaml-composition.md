# YAML composition

Cordis PHP keeps the runtime-owned envelope small and closed. Each list item
has a stable `id` and is either a plugin (`name`) or a group (`group`). The
plugin's `config` value is deliberately opaque to the loader: its registered
plugin validates that value after safe expressions are resolved.

Use [the schema](../resources/schema/composition.schema.yaml) for an early,
editor- and CI-friendly envelope check:

```sh
ys -f resources/schema/composition.schema.yaml examples/03-yaml-live-reload/composition.initial.yaml
```

Then use the runtime for tag-aware validation and lifecycle reconciliation:

```php
$loader = $runtime->yaml(__DIR__.'/composition.yaml');
$report = $loader->reload();
```

## Entry fields

| Field | Meaning |
| --- | --- |
| `id` | Stable sibling-unique identity; drives reconciliation and patches. |
| `name` | Registered plugin name; excludes `group`. |
| `group` | Nested entries under an inert lifecycle node; excludes `name`. |
| `config` | Plugin data for a plugin entry; may contain safe expressions. |
| `disabled` | Boolean or expression; absent from the live tree when true. |
| `inject` | Explicit service requirements; absent ones leave a fiber pending. |
| `isolate` | Service names hidden from the child realm. |
| `intercept` | Per-service metadata exposed by `Context::interceptionFor()`. |

## Safe expressions

Expressions are data, never PHP. They can occur anywhere inside `config`, or
as the entire `disabled` value. Both of these spellings are accepted:

```yaml
config:
  api_key: !expr env:API_KEY
  endpoint:
    $expr:
      concat:
        - https://
        - !expr service:api-host
        - /v1
```

The vocabulary is deliberately small: `env`, `service`, `coalesce`, and
`concat`. `env` can use `default`. Expressions in lifecycle metadata such as
`name`, `inject`, `isolate`, and `intercept` are rejected.

## Reconciliation and overlays

`YamlRuntimeLoader::reload()` mounts new entries, restarts a leaf whose config
changes, and disposes entries removed from the document. Before applying a
candidate tree it resolves every referenced plugin, so an unknown name leaves
the prior healthy tree live.

Pass id-targeted overlays to `reload()` when a deploy, tenant, or environment
needs a small variation without mutating the base file:

```php
$loader->reload([
    ['id' => 'logger', 'config' => ['channel' => 'audit']],
    ['id' => 'infrastructure', 'insert' => [
        ['id' => 'metrics', 'name' => 'metrics'],
    ]],
]);
```

An overlay without `insert` replaces supplied fields on its target. An overlay
with `insert` only inserts children into a group (or roots when `id` is
omitted), keeping those two operations unambiguous.
