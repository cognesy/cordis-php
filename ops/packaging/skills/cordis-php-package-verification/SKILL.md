---
name: cordis-php-package-verification
description: Verify the Cordis PHP package archive and its release-facing contents.
---

# Cordis PHP package verification

Use this skill when changing package metadata, archive attributes, source
layout, public resources, or the release-facing examples.

1. Read `composer.json` and `.gitattributes` before deciding whether a path is
   runtime material or development-only material.
2. Keep source, the composition schema, and representative examples in the
   archive. Exclude tests, CI setup, lock files, and repository operations.
3. Run `just ops packaging archive` to inspect the exact Git archive, using
   working-tree attributes so pending attribute changes are exercised.
4. If archive contents change intentionally, update the package assertion in
   the capability script and explain the new delivery contract.

Do not infer package contents from the worktree; archive attributes are the
authoritative distribution boundary.
