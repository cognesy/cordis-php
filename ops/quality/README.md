# Quality

Quality is intentionally a thin adapter over the repository's authoritative
Composer scripts. It exposes metadata validation, PHPStan analysis, and Pint
check mode individually, then retains `composer qa` as the full suite.

Run `just ops quality analysis` while working on source, or use `just ops
control aggregate check` to include this capability in the repository-wide
check lane.
