# Changelog

All notable changes to Cordis PHP are documented here.

## [1.0.1] - 2026-08-26

- Corrected the checked release-archive validator so Git archive directory
  entries are not mistaken for development files. No development file is
  included in the distributable source archive.

## [1.0.0] - 2026-08-26

- First stable release of the strictly typed PHP plugin runtime.
- YAML composition, reversible plugin lifecycles, dynamic services, patches,
  expressions, isolation, interception, and runtime observability.
- Supports PHP 8.2 and newer with Symfony YAML 7 or 8, including a compatible
  CI matrix for both Symfony lines.
- Prunes released effects and disposed child scopes so repeated worker
  reconciliation does not retain every previous fiber attempt.
- Unpublishes plugin-provided services and stops their dependents before
  provider cleanup closes the resources behind those services.
