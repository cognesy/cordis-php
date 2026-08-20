# GitHub release workflow

Release is deliberately marked experimental until a maintained release policy
and package publication destination are established. Its `status` command is
read-only; its `gate` command requires clean local evidence; and `publish`
requires an explicit semantic version before it can create a GitHub release.

Use `just ops release status` to inspect state. Only run `publish` when an
authorized release is the requested outcome.
