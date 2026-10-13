---
title: State history
description: Ask Home Assistant what an entity did over the last minutes or hours.
sidebar:
  order: 10
---

`getState()` tells you what an entity is doing now. `getHistory()` asks Home Assistant's recorder what it did before.
This app turns off the hall light when nobody has moved there for half an hour:

```php file=../../examples/apps/ForgottenLight.php
```

`getHistory()` exists on `HaContext`, as in the example, and on every [typed entity](codegen.md).

## Choosing the time window

| Query | Covers |
|---|---|
| `HistoryQuery::lastFor(Duration::hours(1))` | The last hour, up to now |
| `HistoryQuery::between($from, $until)` | A fixed window, between two `Instant`s |

The answer holds one entry per state change, without attributes. Two methods add more:

| Method | Adds |
|---|---|
| `->withAttributes()` | The attributes at each state change |
| `->withAttributeChanges()` | Also the moments when only attributes changed, such as a light's brightness |

A long window with attributes can be larger than `home_assistant.message_size_limit`, which drops the connection.
Keep such windows short.

## Reading the answer

| Method | Answers |
|---|---|
| `hasBeenIn('on')` | Was it in this state (or one of several) at any moment in the window? |
| `getDurationIn('on')` | For how long, as a `Duration` |
| `countChanges()` | How many times the state changed |
| `getLastChangeTo('on')` | When it last changed to this state, or `null` |
| `getStateAtStart()`, `getLastState()` | The first and the last entry |

The first entry is the state the entity was in when the window started. A light that stayed on the whole time still
answers `hasBeenIn('on')`. That first entry does not count as a change.

`$history->states` holds every entry, oldest first. An entity that the recorder does not keep, or that did not exist
yet, has an empty history.

## Cost and failures

`getHistory()` asks Home Assistant, so it waits for the answer like `callService()` and shares its timeout and limits.
Do not call it on every change of a busy sensor; a schedule, as in the example, is a better fit.

It throws `HistoryException`. The reason is `RecorderUnavailable` when Home Assistant has no recorder, or
`Rejected`, `Unreachable`, `TimedOut` or `Overloaded`, as for [service calls](calling-services.md#when-a-call-fails).

## Testing

The test harness keeps a history of every state it sees, at the virtual clock's time. `seedHistoricalState()` adds
one in the past:

```php file=../../examples/tests/ForgottenLightTest.php
```

`$harness->ha->historyQueries` lists the queries the app made, and `stubHistoryFailure()` makes one entity's history
fail.

## Common problems

**The history is empty.** The recorder may exclude the entity. Check `recorder:` in Home Assistant's
`configuration.yaml`.

**`RecorderUnavailable`.** Home Assistant runs without the `recorder` or `history` integration.
