---
name: cordis-php-composition-contracts
description: Safely evolve Cordis PHP YAML composition contracts, fixtures, and examples.
---

# Cordis PHP composition contracts

Use this skill for changes to the YAML envelope, expression grammar, patching
rules, or executable composition examples.

1. Read `resources/schema/composition.schema.yaml` and the relevant loader or
   parser before proposing a schema change.
2. Treat the schema, acceptance tests, valid examples, and invalid fixture as
   one compatibility surface; update all affected evidence deliberately.
3. Preserve the closed runtime envelope and keep plugin `config` opaque unless
   the runtime contract intentionally changes.
4. Run `just ops spec schema`, then the focused tests and examples that expose
   the changed behavior.
5. Run `just ops workflow ci` before handing off a schema-level change.

Do not make an example pass by weakening validation without documenting and
testing the intended new contract.
