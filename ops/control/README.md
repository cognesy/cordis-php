# Operations control

This capability is the registry and safety rail for the entire `ops/` tree.
It validates versioned manifests, schemas, capability dependencies, ownership,
`just` recipes, and declared agent skills before an aggregate lane can run.

Use `just ops control list` to discover capabilities and `just ops control
validate` before changing the operations catalogue. `doctor` checks tool
availability, while `aggregate check` and `aggregate test` derive their work
from capability metadata.
