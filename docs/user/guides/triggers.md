---
title: Triggers
description: Use Home Assistant's own triggers, such as zones, templates, time patterns and calendars, from an app.
sidebar:
  order: 12
---

Some moments are neither a state change nor an event: a person entering a zone, a template turning true, a calendar
event starting. Home Assistant detects these with **triggers**, and `watchTrigger()` lets an app use them. This app
turns the hall light on when Anna comes home and the ground floor off when she leaves:

```php file=../../examples/apps/WelcomeHome.php
```

`watchTrigger()` returns a stream of `TriggerEvent`s, with the usual operators (`filter`, `debounce`, `take`, …).

## Built-in triggers

| Builder | Fires |
|---|---|
| `HaTrigger::onZoneTransition('person.anna', 'zone.home', ZoneTransition::Enter)` | When a person or device tracker enters or leaves a zone |
| `HaTrigger::whenTemplateTrue('{{ … }}', for: Duration::minutes(2))` | When a template turns true, optionally after it stayed true that long |
| `HaTrigger::onTimePattern(minutes: '/5')` | On a clock pattern; `hours`, `minutes` and `seconds` each take `'/n'`, `'*'` or a number |
| `HaTrigger::atTime('07:30')` | Every day at that time |
| `HaTrigger::atTime('input_datetime.wake_up')` | At the time an `input_datetime` or timestamp sensor holds |
| `HaTrigger::onSunrise()` / `onSunset(SunOffset::before(…))` | At sunrise or sunset |

Each builder takes an optional `id:`, which comes back as `$event->getTriggerId()`.

**Prefer Stewart's own tools where they exist.** For times and the sun, a [schedule](scheduling.md) keeps working
while Home Assistant is unreachable; a trigger does not. For state changes, `watchStateChanges()` runs inside Stewart
and needs no subscription in Home Assistant.

## Any other trigger

Every other trigger Home Assistant has (`calendar`, `numeric_state`, `conversation`, `device`, …) goes in as the array
you would write in YAML:

```php
$this->ha->watchTrigger(['trigger' => 'calendar', 'event' => 'start', 'entity_id' => 'calendar.bins']);
```

## Several triggers

A `HaTriggerCollection`, as in the example, fires when any of its triggers fires. It is one subscription in Home
Assistant; the `id:` tells the triggers apart. A plain list of arrays works the same way.

## Variables

Template triggers can read values you pass:

```php
$this->ha->watchTrigger(
    HaTrigger::whenTemplateTrue('{{ states(sensor) | float(0) > limit }}'),
    variables: ['sensor' => 'sensor.hall_temperature', 'limit' => 25],
);
```

## What a TriggerEvent holds

| Method | Returns |
|---|---|
| `getTriggerId()` | Your `id:`, or the trigger's position in the list |
| `getPlatform()` | `zone`, `template`, `time`, … |
| `getValue('to_state')`, `getTriggerData()` | Home Assistant's `trigger` variables |
| `$event->context` | Who caused it |
| `$event->firedAt` | When Stewart received it |

## How triggers behave

- **Shared.** Apps that watch the same trigger with the same variables share one subscription in Home Assistant.
- **Outages.** After Stewart reconnects, every trigger is subscribed again. A trigger that would have fired while the
  connection was down is lost.
- **Rejected triggers.** Home Assistant checks a trigger when Stewart subscribes. If it refuses, Stewart logs Home
  Assistant's reason and the subscription ends. The builders catch most mistakes earlier, with a
  `TriggerException`.
- **Home Assistant version.** The builders need Home Assistant 2024.10 or newer. On older versions, pass arrays with
  `platform:` instead of `trigger:`.

## Testing

The harness records what an app watches. `pushTrigger()` fires every watcher of an equal trigger:

```php file=../../examples/tests/WelcomeHomeTest.php
```

## Common problems

**The trigger never fires, and the log shows Home Assistant's error.** Home Assistant rejected the trigger. Try the
same trigger in a Home Assistant automation to see the problem.

**It missed a moment.** The connection was down at that moment; triggers are not replayed. Use a schedule for times
of day.
