# Local delivery workflow

Workflow composes rather than reimplements: it checks the declared local tool
surface, then runs the manifest-derived check and test lanes. Adding a checked
or tested capability updates delivery automatically through its manifest.

Run `just ops workflow ci` before handing off a substantive change.
