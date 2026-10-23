---
title: Call actions
description: Turn lights on, send notifications and read answers from Home Assistant actions, and handle calls that fail.
sidebar:
  order: 6
---

Apps change things in Home Assistant by calling its **actions**, which older Home Assistant releases called services:
`light.turn_on`, `notify.notify`, `cover.close_cover`. This app reads the weather forecast every morning and warns you
when it will rain:

```php file=../../examples/apps/RainWarning.php
```

## Calling an action

```php
$this->ha->callAction('light.turn_on', ['brightness_pct' => 60], ActionTarget::forEntities('light.hall'));
```

The arguments are the action, its data, and the target. Data and target are optional. The action is written as Home
Assistant writes it, `domain.action`; Home Assistant lists every action and its data under **Developer tools →
Actions**. A name without exactly one dot throws `ActionCallError::ActionNameInvalid` before anything is sent.

Stewart 2.0 removed the older `callService('light', 'turn_on', …)`; [Upgrading](../help/upgrading.md) has a command
that rewrites such calls.

The target picks what the action acts on:

| Target | Acts on |
|---|---|
| `ActionTarget::forEntities('light.hall', 'light.porch')` | Those entities |
| `ActionTarget::forAreas('kitchen')` | Everything in the area |
| `ActionTarget::forFloors('upstairs')` | Everything on the floor |
| `ActionTarget::forLabels('night')` | Everything with the label |
| `ActionTarget::forDevices('a1b2c3…')` | Everything on the device |

`callAction()` returns when Home Assistant has answered. Other apps keep running meanwhile. It returns an
`EventContext` that identifies the call, which [Events](events.md#your-own-changes-or-someone-elses) explains.

[Typed entities](codegen.md) offer the same calls as methods, such as
`$this->entities->light->getEntity('light.hall')->turnOn()`, with your editor completing the names. An untyped
`Entity` calls its own domain's actions: `$this->ha->getEntity('light.hall')->callAction('turn_on')`.

## Reading an answer

Some actions answer with data, such as `weather.get_forecasts` or `calendar.get_events`. Call those with
`callActionForResponse()`. Its `response` property holds the answer as Home Assistant sends it: an array keyed by
entity ID. The example checks each level of that array before it reads it, because nothing guarantees its shape.

## When a call fails

A failed call throws `ActionCallException`. Its `reason` says why:

| Reason | Means |
|---|---|
| `ActionCallError::Rejected` | Home Assistant refused the call, for example an unknown action or wrong data |
| `ActionCallError::Unreachable` | Stewart is not connected to Home Assistant right now. The call is not queued |
| `ActionCallError::TimedOut` | Home Assistant did not answer within `home_assistant.command_timeout` (30 seconds) |
| `ActionCallError::Overloaded` | Too many calls were waiting for an answer at once; see below |

If your handler does not catch the exception, Stewart logs it as a failure of the app, and the app keeps running.
Catch it where the app can do something useful, as the example does:

```php
try {
    $this->ha->callAction('lock.lock', target: ActionTarget::forEntities('lock.front_door'));
} catch (ActionCallException $e) {
    $this->logger->warning('Could not lock the door', ['reason' => $e->reason->value]);
}
```

## Limits

A worker may have 64 calls waiting for an answer at once, and all workers together 256. Further calls fail with
`Overloaded` until answers come back. A loop that calls an action for hundreds of entities can reach this; use one
call with a target that names all of them instead. The limits are `ha_calls.max_in_flight_per_worker` and
`ha_calls.max_in_flight`; fired events and registry edits count against them too.

## Dry run

With dry run on, Stewart logs each call instead of sending it, and answers it as if it succeeded. Use it to try an
app against your real Home Assistant without changing anything:

```bash
make run ONLY=rain-warning DRY_RUN=1
```

The log shows `Action call not sent: ha_calls.dry_run is on`, with the action, the data and the target. `DRY_RUN=1`
passes `--dry-run` to `stewart run`, which turns on the `ha_calls.dry_run` setting; fired events and registry edits
are held back too.

## Testing

The test harness records every call. `stubActionAnswer()` sets what an action answers, and
`stubActionFailure()` makes it fail:

```php file=../../examples/tests/RainWarningTest.php
```

`$harness->ha->getLastCall()` returns the last call, and `$harness->ha->calls` all of them. Each has
`getActionName()` (`'notify.notify'`), `data`, and `listTargetedEntityIds()`.

## Common problems

**`was rejected by Home Assistant`.** The message carries Home Assistant's reason. Try the same call under
**Developer tools → Actions**.

**Calls fail with `could not reach Home Assistant`.** Stewart was reconnecting. Check its log for connection
messages, and `make status`.
