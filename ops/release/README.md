# Packagist and GitHub release workflow

The Composer package is published to Packagist from the public GitHub
repository. Packagist reads the stable `v<version>` tags; the GitHub Release is
the human-facing publication and carries a source archive, checksum, and
machine-readable manifest.

`ops/version/current.yaml` is the one version authority. It deliberately is not
duplicated as a `version` field in `composer.json`: Composer derives package
versions from VCS tags.

Inspect and validate locally:

```sh
just ops version show
just ops version check
just ops version next patch
just ops release status
just ops release gate
```

Prepare a release in this order:

```sh
# For the first release, current.yaml already carries 0.1.0. For later
# releases, advance it first, for example: just ops version set 0.1.1
# update CHANGELOG.md, then:
just ci
git add CHANGELOG.md ops/version/current.yaml composer.json
git commit -m "Release v0.1.0"
git push origin main
git tag -a v0.1.0 -m "Cordis PHP v0.1.0"
git push origin v0.1.0
just ops version verify-release
just ops release package
just ops release publish 0.1.0
```

The tag-triggered `.github/workflows/release.yml` repeats the immutable tag
check and full CI gate, builds the same release assets, and creates the GitHub
Release. A tag is never moved or reused to repair a bad publication; advance
to a new patch version instead.

`status` is read-only. `gate` requires a clean worktree and passing local
evidence. `package` and `publish` require a clean, annotated, pushed tag.
