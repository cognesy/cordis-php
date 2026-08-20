# Cordis PHP's repository-operation door.
#
# The implementations, their schemas, and agent procedures live under `ops/`.
# This file intentionally exposes only discovery, whole-repository lanes, and
# the generic capability address. Composer remains the PHP tool surface.

set shell := ["bash", "-euo", "pipefail", "-c"]
set positional-arguments := true

ROOT := justfile_directory()
OPS := ROOT / "ops"

# Show the complete local command surface.
[group("Discovery")]
default:
    @just --list

# Alias for the local command catalogue.
[group("Discovery")]
help:
    @just --list

# Run any capability command: `just ops release status`.
[group("Repository operations")]
ops capability command *args:
    @just --justfile {{ OPS }}/{{ capability }}/justfile --working-directory {{ ROOT }} {{ command }} {{ args }}

# Inspect the declared operations catalogue.
[group("Repository operations")]
list *args:
    @just --justfile {{ OPS }}/control/justfile --working-directory {{ ROOT }} list {{ args }}

# Validate manifests, schemas, ownership, and declared command surfaces.
[group("Repository operations")]
validate *args:
    @just --justfile {{ OPS }}/control/justfile --working-directory {{ ROOT }} validate {{ args }}

# Check every executable and tool declared by the operation manifests.
[group("Repository operations")]
doctor *args:
    @just --justfile {{ OPS }}/control/justfile --working-directory {{ ROOT }} doctor {{ args }}

# Run the derived fast repository-wide quality lane.
[group("Repository operations")]
check *args:
    @just --justfile {{ OPS }}/control/justfile --working-directory {{ ROOT }} aggregate check {{ args }}

# Run the derived repository-wide test lane.
[group("Repository operations")]
test *args:
    @just --justfile {{ OPS }}/control/justfile --working-directory {{ ROOT }} aggregate test {{ args }}

# Run the CI-shaped local workflow.
[group("Repository operations")]
ci:
    @just --justfile {{ OPS }}/workflow/justfile --working-directory {{ ROOT }} ci

# Compatibility alias for the full local workflow.
[group("Repository operations")]
qa: ci

# Compatibility alias for `just list`.
[group("Compatibility")]
ops-list: list

# Compatibility alias for `just validate`.
[group("Compatibility")]
ops-validate: validate

# Compatibility alias for `just doctor`.
[group("Compatibility")]
ops-doctor: doctor

# Compatibility alias for `just check`.
[group("Compatibility")]
ops-check: check

# Compatibility alias for `just test`.
[group("Compatibility")]
ops-test: test
