---
title: Triggers
description: React to sunset, a time of day, a template turning true or a person leaving a zone, using Home Assistant's own triggers.
sidebar:
  order: 7
---

Some moments are not a state change and not an event: sunset, 07:30, the moment
a template turns true, a person leaving home. Home Assistant already knows how to
detect them; it calls them triggers. `watchTrigger()` hands one to Home Assistant
and streams every time it fires:

```php
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\SunOffset;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerEvent;

$this->ha->watchTrigger(HaTrigger::onSunset(SunOffset::before(Duration::minutes(30))))
    ->subscribe(fn(TriggerEvent $event) => $this->ha->callService('light', 'turn_on', target: $this->ha->getEntity('light.porch')));
```

The stream is an ordinary `EventStream<TriggerEvent>`: `filter()`, `debounce()`,
`take()` and the rest work on it.

For a sun moment as a `ScheduledTask` that keeps running while Home Assistant is
unreachable, use `runAtSunEvent(SunEvent::Sunset, ...)` instead; see [The sun](sun.md).

## Building a trigger

| Builder | Fires |
|---|---|
| `HaTrigger::onSunrise(?SunOffset)` / `onSunset(?SunOffset)` | at sunrise or sunset; `SunOffset::before(...)` / `after(...)` moves it |
| `HaTrigger::atTime('07:30')` | every day at that time (`HH:MM` or `HH:MM:SS`) |
| `HaTrigger::atTime('input_datetime.wake_up')` | at the time an `input_datetime` or a timestamp `sensor` holds, following its changes; `Scheduler::runAtEntityTime()` does the same without a Home Assistant subscription |
| `HaTrigger::onTimePattern(minutes: '/5')` | on a clock pattern: `hours`, `minutes`, `seconds`, each `'/n'`, `'*'` or a number |
| `HaTrigger::whenTemplateTrue('{{ ... }}', for: Duration::minutes(2))` | when the template turns true, optionally after it stayed true that long |
| `HaTrigger::onZoneTransition('person.anna', 'zone.home', ZoneTransition::Leave)` | when the person or device tracker enters or leaves the zone |

Every builder takes an optional `id:`; it comes back as `$event->getTriggerId()`,
so a handler can tell which trigger of a list fired.

Anything else Home Assistant supports — `calendar`, `device`, `conversation`,
`numeric_state` and the rest — goes in as the array you would write in YAML:

```php
$this->ha->watchTrigger(['trigger' => 'calendar', 'event' => 'start', 'entity_id' => 'calendar.bins']);
```

`HaTrigger::fromArray()` does the same and checks the map has a `trigger` (or the
older `platform`) key. For state changes, prefer `watchStateChanges()`: it runs in
the worker and reads the local state cache.

## Several triggers at once

A list fires when any of its triggers does. It is one Home Assistant
subscription, and the trigger id tells them apart:

```php
use Stewart\Contracts\Trigger\Collection\HaTriggerCollection;

$this->ha->watchTrigger(HaTriggerCollection::fromTriggers([
    HaTrigger::onSunrise(id: 'morning'),
    HaTrigger::atTime('07:00', id: 'morning'),
    HaTrigger::onSunset(id: 'evening'),
]))->subscribe(fn(TriggerEvent $event) => $this->applyScene($event->getTriggerId()));
```

A plain list of arrays works the same way.

## Variables

Template triggers can read values you pass in:

```php
$this->ha->watchTrigger(
    HaTrigger::whenTemplateTrue('{{ states(sensor) | float(0) > limit }}'),
    variables: ['sensor' => 'sensor.hall_temperature', 'limit' => 25],
);
```

## What a TriggerEvent carries

| | |
|---|---|
| `$event->getPlatform()` | `sun`, `time`, `template`, ... |
| `$event->getTriggerId()` | your `id:`, or the trigger's position in the list as a string |
| `$event->getTriggerData()` / `$event->getValue($key)` | Home Assistant's `trigger` variables, such as `event`, `entity_id`, `from_state` |
| `$event->context` | the id, parent and user behind it |
| `$event->firedAt` | when the broker received it |

## How it behaves

- **Shared.** Apps that watch the same trigger (same config, same variables)
  share one Home Assistant subscription. It ends when the last of them stops.
- **Across outages.** After Home Assistant comes back, every trigger is
  subscribed again. A trigger that would have fired during the outage is lost;
  Home Assistant does not replay it.
- **Rejected triggers.** Home Assistant checks the config when Stewart subscribes.
  If it refuses, the error is logged with Home Assistant's reason and the
  subscription ends: `$subscription->isActive()` turns false. The builders and
  `fromArray()` catch shape mistakes earlier, with a `TriggerException`.
- **Version.** The builders use the `trigger:` key, which needs Home Assistant
  2024.10 or newer. On older versions, pass arrays with `platform:`.
- **Delivery.** Fired triggers queue like events: a worker that falls behind
  drops its oldest queued ones, with a warning.

## Testing

`RecordingHaContext` records what an app watches and lets a test fire it:

```php
$ha = new RecordingHaContext();
$app = new PorchLight($ha);
$app->initialize();

$ha->pushTrigger(HaTrigger::onSunset(SunOffset::before(Duration::minutes(30))), new TriggerEvent(['platform' => 'sun']));

self::assertSame('turn_on', $ha->getLastCall()->service);
self::assertCount(1, $ha->listWatchedTriggers());
self::assertSame([], $ha->listWatchedTriggers()->getFirst()?->variables);
```

`pushTrigger()` reaches every watcher whose triggers are equal, whatever its
variables. A watch is recorded when `watchTrigger()` is called, before anything
subscribes. [`AppHarness`](testing.md) gives an app the same fake as `$harness->ha`.
