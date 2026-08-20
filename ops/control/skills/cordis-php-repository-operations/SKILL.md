---
name: cordis-php-repository-operations
description: Navigate, validate, and extend the Cordis PHP repository operations catalogue.
---

# Cordis PHP repository operations

Use this skill when changing repository automation, adding an operational
capability, or deciding which local gate applies to a change.

1. Discover the active capability surface with `just ops control list`.
2. Read `ops/ops.yaml`, then the selected capability's `capability.yaml`
   before editing implementation files.
3. Keep each `ops/` file owned by exactly one capability. Record external
   inputs in `reads`, not in `owns`.
4. Add a matching `justfile` recipe for every declared command and declare any
   new skill in the same manifest.
5. Run `just ops control validate`; run the applicable capability command; use
   `just ops workflow ci` when the change affects delivery confidence.

Do not duplicate aggregate command lists. Mark a command's `aggregate` lane in
its manifest so control can derive the repository-wide route.
