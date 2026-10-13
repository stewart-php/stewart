---
title: State history
description: Ask Home Assistant what an entity did over the last minutes or hours.
sidebar:
  order: 5
---

`getState()` tells you where an entity is now. `getHistory()` asks Home
Assistant's recorder where it has been:

```php
use Stewart\Contracts\History\HistoryQuery;
use Stewart\Contracts\Time\Duration;

$history = $this->ha->getEntity('binary_sensor.hall_motion')
    ->getHistory(HistoryQuery::lastFor(Duration::minutes(30)));

if (!$history->hasBeenIn('on')) {
    $this->ha->callService('light', 'turn_off', target: $this->ha->getEntity('light.hall'));
}
```

The same call exists on `HaContext::getHistory($entityId, $query)` and on every
generated entity handle.

## Choosing the window

| Query | Window |
|---|---|
| `HistoryQuery::lastFor(Duration::hours(1))` | the hour up to now, resolved when the call is made |
| `HistoryQuery::between($from, $until)` | a fixed window; it must end after it starts |

By default the answer holds one entry per state change, without attributes,
which keeps it small. Two modifiers widen it:

| Modifier | Adds |
|---|---|
| `->withAttributes()` | the attributes at each state change |
| `->withAttributeChanges()` | also the entries where only attributes changed, such as a light's brightness while it stays `on`; implies `withAttributes()` |

A long window with attributes can exceed `home_assistant.message_size_limit`,
which drops the connection; keep those windows short.

## Reading the answer

The first entry is the state the entity was in when the window started, so a
light that stayed on the whole time still answers `hasBeenIn('on')`. That
entry is never counted as a change.

| Method | Answers |
|---|---|
| `hasBeenIn('on', ...)` | was it in any of these states at some point in the window |
| `getDurationIn('on', ...)` | for how long, as a `Duration` |
| `countChanges()` | how many times its state changed |
| `getLastChangeTo('on')` | the last time it changed into that state, or null |
| `getStateAtStart()`, `getLastState()` | the first and last entries |

`$history->states` holds every `EntityState`, oldest first.

An entity the recorder does not keep, or one that did not exist yet, has an
empty history rather than an error.

## Cost and failures

`getHistory()` is a request to Home Assistant, not a cache read: it suspends
the handler like `callService()` and shares its timeout. Do not call it on
every state change of a busy sensor.

It throws a `HistoryException`. Its reason is a `HistoryError`:
`RecorderUnavailable` when Home Assistant has no history integration loaded,
or rejected, unreachable, timed out or overloaded like a service call. History
queries share the `service_calls.max_in_flight` budget with service calls.

## Testing

`RecordingHaContext` answers from what it has seen. Seeded and pushed states
are recorded at the virtual clock's time, and `seedHistoricalState()` places
one in the past:

```php
$ha = new RecordingHaContext();
$ha->seedHistoricalState('light.hall', 'on', $ha->clock->getNow()->minus(Duration::minutes(20)));
$ha->clock->skip(Duration::minutes(5));
$ha->pushState('light.hall', 'off');

$history = $ha->getHistory('light.hall', HistoryQuery::lastFor(Duration::minutes(30)));
// $history->getDurationIn('on') is 25 minutes
```

`$ha->historyQueries` records every query, and `stubHistoryFailure()` makes one
entity's history throw. Like Home Assistant, it leaves out entries that repeat
the previous state unless the query asked for `withAttributeChanges()`.
[`AppHarness`](testing.md) gives an app the same fake as `$harness->ha`.
