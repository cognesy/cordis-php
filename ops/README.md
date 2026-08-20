# Cordis PHP repository operations

`ops/` is the executable map of how this repository is maintained. It does not
replace Composer. Instead, it states which capability owns an operation, what
it needs, which paths it may change, which shell commands it exposes, and which
agent procedures apply.

Start with the catalogue:

```sh
just ops control list
just ops control validate
just ops control doctor
```

For a machine-readable control response, pass `--json` through the same
address:

```sh
just ops control list --json
```

Run a capability through its stable address:

```sh
just ops quality analysis
just ops spec schema
just ops packaging archive
```

## Design rules

- `ops/ops.yaml` chooses one active provider for each operational interface.
- Every `ops/<capability>/capability.yaml` is versioned and declares its
  dependencies, ownership, commands, and agent skills.
- Every file under `ops/` has exactly one owner. Reads and generated paths are
  recorded separately so a directory boundary means something during review.
- `just` implements commands; manifests index them. The `check` and `test`
  lanes are assembled from manifest metadata rather than copied into another
  hand-maintained list.
- Skills are declared in their capability manifest, have a `SKILL.md` with
  trigger metadata, and carry versioned `agents/openai.yaml` presentation data.

The control validator checks these contracts, the path graph, the active
provider table, recipes, skill metadata, and all three YAML schema families.
