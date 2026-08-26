---
name: cordis-php-release-publishing
description: Perform a deliberate, guarded Packagist and GitHub release for Cordis PHP.
---

# Cordis PHP release publishing

Use this skill only when Packagist/GitHub publication is explicitly authorized.

1. Start with `just ops version show` and `just ops release status`.
2. Confirm `CHANGELOG.md`, `composer.json`, and the version authority are
   coherent with `just ops version check`.
3. Run `just ops release gate`; it requires a clean worktree and the full
   local delivery workflow.
4. Confirm that the annotated `vMAJOR.MINOR.PATCH` tag points at clean `HEAD`
   and is pushed with `just ops version verify-release`.
5. Build and inspect the exact assets with `just ops release package`.
6. Only after those checks and explicit authorization, run `just ops release
   publish <version>` or push the tag to let GitHub Actions publish it.
7. Record the resulting Release URL and Packagist availability. Do not retag
   or overwrite a published artifact to repair a mistake; publish a new patch.

Packagist ingests the pushed Git tag; it is not a second package upload command.
Do not treat a passing local gate as publishing authority.
