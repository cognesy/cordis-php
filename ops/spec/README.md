# YAML composition specification

The runtime schema in `resources/schema/` is a public contract. This
capability checks every YAML composition example against it and verifies that
the intentionally invalid fixture remains rejected.

Run `just ops spec schema` after changing the schema, an example composition,
or the YAML loader. The paired skill records the decision procedure for schema
changes rather than treating validation as an afterthought.
