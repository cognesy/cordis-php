---
name: cordis-php-release-publishing
description: Perform a deliberate, guarded GitHub release for Cordis PHP.
---

# Cordis PHP release publishing

Use this skill only when a GitHub release is explicitly authorized.

1. Start with `just ops release status` and inspect the branch, revision,
   working-tree state, and repository identity.
2. Run `just ops release gate`; it requires a clean worktree and the full
   local delivery workflow.
3. Confirm that an existing annotated or lightweight `vMAJOR.MINOR.PATCH` tag
   points at the intended commit.
4. Only after those checks and explicit authorization, run `just ops release
   publish <version>`.
5. Record the resulting release URL and do not retag or overwrite a published
   artifact to repair a mistake; publish a new patch instead.

The capability is experimental because package publication policy remains an
organizational decision. Do not treat a passing local gate as publishing
authority.
