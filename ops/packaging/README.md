# Package integrity

This capability checks the exact archive Git would create from `HEAD`. It
asserts that the runtime schema and representative example ship, while tests,
CI configuration, lock files, and static-analysis configuration do not.

Run `just ops packaging archive` after changing `.gitattributes`, source
layout, or package-facing resources.
