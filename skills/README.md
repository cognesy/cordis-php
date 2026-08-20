# Cordis PHP solution skills

This catalogue turns the verified Cordis PHP runtime contracts into focused
procedures for humans and coding agents. Start with the skill matching the
change; combine it with the repository-facing procedures in `ops/*/skills`
only when the repository itself must change.

## Choose a skill

- [`cordis-php-solution-design`](cordis-php-solution-design/SKILL.md): plugin,
  service, event, isolation, and composition boundaries. Examples 01, 02, 04,
  06, and 09 are its starting proof.
- [`cordis-php-agent-harness`](cordis-php-agent-harness/SKILL.md): model, tool,
  policy, session, and trace wiring. Start with examples 02 and 04.
- [`cordis-php-plugin-development`](cordis-php-plugin-development/SKILL.md): new
  or changed plugin implementations. Start with examples 01 and 07.
- [`cordis-php-composition-configuration`](cordis-php-composition-configuration/SKILL.md):
  YAML documents, expressions, patches, and reloads. Start with examples 03
  and 08.
- [`cordis-php-runtime-refactoring`](cordis-php-runtime-refactoring/SKILL.md):
  safe change to an established solution or runtime boundary. Start with the
  runtime and YAML lifecycle tests.
- [`cordis-php-runtime-operations`](cordis-php-runtime-operations/SKILL.md):
  health, status, reload, telemetry, and runbooks. Start with example 05.
- [`cordis-php-incident-troubleshooting`](cordis-php-incident-troubleshooting/SKILL.md):
  pending, failed, restart, reload, and cleanup incidents. Start with examples
  02, 05, 07, and 08.
- [`cordis-php-solution-verification`](cordis-php-solution-verification/SKILL.md):
  lifecycle, composition, and delivery confidence. Use tests, schemas, and
  executable examples as proof.

## Scenario inventory

- **Design — notification or integration bridge:** scoped service, listener, and
  cleanup. Start with solution design.
- **Design / operate — rotating model, database, or credential endpoint:** a
  declared dependency becomes pending, then restarts on replacement. Start with
  solution design or runtime operations.
- **Configure / deploy — safe configuration rollout:** YAML expression and
  reconciliation report. Start with composition configuration.
- **Develop — prompt policy, tool resolution, or extension point:** event
  waterfall, bail, serial, or parallel dispatch. Start with agent harness or
  plugin development.
- **Operate — health and telemetry adapter:** fiber status transitions and a
  runtime snapshot. Start with runtime operations.
- **Design / security — tenant or workload boundary:** isolated service realm
  with explicit missing dependency. Start with solution design.
- **Develop / harden — worker or webhook settings:** plugin-owned configuration
  validation before side effects. Start with plugin development.
- **Deploy / refactor — swap storage, cache, transport, or credentials:**
  guarded YAML patch and service restart. Start with composition configuration
  or refactoring.
- **Configure / operate — per-workload retry, timeout, or routing policy:**
  immutable local service interception metadata. Start with composition
  configuration.
- **Verify / release — any change above:** contract tests, schemas, examples,
  and a CI-shaped gate. Start with solution verification.

## Shared operating facts

- Target PHP 8.4 or newer. Cordis PHP is synchronous; let the host own the
  event loop, worker supervisor, HTTP server, and long-running agent loop.
- Treat a plugin fiber and its child scope as the ownership boundary. Services,
  event subscriptions, and cleanup registered through `Context` are released
  with that boundary in reverse registration order.
- Treat `pending`, `active`, `failed`, and `disposed` as operational states,
  not vague log labels. Inspect missing services and the captured error before
  changing configuration.
- Treat the YAML envelope as closed and plugin `config` as plugin-owned.
  Validate the envelope with the schema and validate domain values in the
  registered plugin.
- Use `just` for repository gates: `just validate`, `just check`, and
  `just ci`. Use the relevant executable example as a small integration proof.

## Boundaries

These are solution skills, not deployment authority. Do not publish a release,
run destructive migration, or change production configuration without the
authorization and environment evidence required by the surrounding operation.
