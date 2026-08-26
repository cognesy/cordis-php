# Release versioning

`ops/version/current.yaml` is the single authority for the stable version of
the `cordis-php/cordis` Composer package. The package manifest intentionally
does not duplicate that value: Packagist derives installable versions from
the pushed Git tags.

Inspect the release record and preview the next version with:

```sh
just ops version show
just ops version check
just ops version next patch
```

`set` only advances the authority, requires a clean worktree, and does not
commit, tag, push, or publish:

```sh
just ops version set 1.0.1
```

`verify-release` is the final tag guard. It requires the current `v<version>`
tag to be annotated, to resolve to `HEAD`, to have no uncommitted changes, and
to match the tag object pushed to `origin`.
