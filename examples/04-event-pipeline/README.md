# Event pipeline: prompt policy and tool resolution

## Context

An agent needs independently deployable prompt policies and tool providers.
Each extension should contribute behavior without the agent core knowing every
plugin that exists.

## Problem solved

Direct callbacks produce a central switch statement and make removal risky.
An extension can leave a callback behind after it has been disabled.

## Practical use case

The redactor and policy plugins transform a prompt through `waterfall`. The
weather plugin resolves the first matching tool through `bail`. Removing the
redactor proves that its scoped listener is gone immediately.

## Run

```sh
php examples/04-event-pipeline/run.php
```

## Expected outcome

The first prompt is redacted and policy-approved. The weather tool resolves,
while an unknown tool returns `null`. After the redactor is disposed, the same
prompt no longer changes that word but still receives the policy suffix.

## APIs demonstrated

- scoped `Context::on()` subscriptions
- `EventBus::waterfall()` for transformation pipelines
- `EventBus::bail()` for first-provider-wins resolution
- deterministic listener removal on fiber disposal
