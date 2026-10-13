---
title: Testing apps
description: Run an app against fakes on virtual time, without Home Assistant.
sidebar:
  order: 21.5
---

`stewart-php/testing` runs an app in a PHPUnit test. Nothing connects to Home Assistant, and time only moves when
the test moves it. A new project already has it in `require-dev`, and `tests/HelloAppTest.php` shows the pattern.

```php file=../../examples/tests/DoorAlarmTest.php
```

## Starting the app

`startApp($class, $options)` builds the app the way the worker does and runs its `initialize()`. The app's ID comes
from its `#[Automation]` attribute. Each constructor parameter is filled from:

- an option with the parameter's exact name; the array stands in for `options:` in `stewart.yaml`, with PHP values
  rather than strings;
- `#[GlobalStore]` and `#[PeerStore('…')]`, from the harness's stores;
- a service passed to `provideService(Type::class, $object)` before the app starts, for what `services.php` would
  give it;
- a fake for each type Stewart injects (see below);
- a class that takes only a `HaContext`, such as the generated `Entities` and `Services`, built over `$harness->ha`;
- the parameter's default, or null when it is nullable.

An option that matches no parameter, a required option that is missing, and a parameter nothing can fill all fail
with `HarnessException`, naming the parameter. One harness runs one app; `stopApp()` cancels its subscriptions and
schedules, then calls `dispose()`.

Seed what the app reads at start (states, registry entries, store values, restored exposures) before `startApp()`.
An entity's first state is not a change, so seed it before pushing the change you test:
`whenChangedTo('on')` ignores a state pushed for an entity it has never seen.

## The fakes

Each fake is a public property of the harness:

| Property | Injected as | What a test does with it |
|---|---|---|
| `ha` | `HaContext` | `seedState()`, `pushState()`, `pushEvent()`, `pushTrigger()`; read `calls`, `getLastCall()`, `firedEvents` |
| `entities` | `EntityExposure` | `seedSnapshot()`; `requireSwitch('key')->pushCommand(...)`, `requireSensor('key')->getValue()` |
| `scheduler` | `Scheduler` | runs as `advanceTime()` passes each occurrence |
| `stores` | `Store`, `ReadableStore` | `openAppStore(new AppId('…'))` and `openGlobalStore()` to seed and read |
| `mqtt` | `Mqtt` | `pushMessage()`; read `published` |
| `logger` | `LoggerInterface` | `listMessagesAt('info')` |
| `registryEditor` | `RegistryEditor` | read `updates`; edits land in `$harness->ha->registry` |
| `clock`, `timers` | `Clock`, `Timers` | the virtual clock |
| `sunCalendar` | `SunCalendar` | sunrise 06:00, solar noon 12:00, sunset 18:00; `stubEventTime()` changes one |

The fakes are usable on their own as well. The per-feature *Testing* sections show `RecordingHaContext`,
`RecordingEntityExposure` and the others built by hand.

## Time

The clock starts at 2026-01-01 00:00 UTC, or at the moment passed to `new AppHarness($start)`, and stands still.
`advanceTime($duration)` moves it. On the way, every timer, schedule, debounce and wait that falls due runs in order,
at its own time. A handler that a timer resumes runs before the next timer is due, so a timer it starts fires in the
same advance when it falls inside it.

```php
$harness->startApp(HallLight::class, ['offAfterMinutes' => 2]);
$harness->ha->pushState('binary_sensor.hall_motion', 'on');

$harness->advanceTime(Duration::seconds(119)); // the light is still on
$harness->advanceTime(Duration::seconds(1));   // runAfter() fired: light.turn_off
```

Schedules follow the [Scheduler](scheduling.md#how-schedules-treat-time) rules with two differences: there is no pause, and an
occurrence is never late or skipped, since virtual time waits for the handler.

## Waits and handlers

Every handler runs in its own fiber. One that calls `waitForState()` or `waitForNext()` suspends without holding up
the test: the push returns, and the handler continues when a later push or `advanceTime()` satisfies or times out
the wait. A handler that throws before it suspends throws out of the push; one that throws after a wait throws out
of the next `advanceTime()`.

Like the worker, the harness delivers nothing to the app until `initialize()` returns. States pushed meanwhile are
held and arrive afterwards, so a wait inside `initialize()` ends only by the shortcut for a state the entity is
already in, or by its timeout, as it would in production.

## Not modelled

- Pausing an app.
- Queue limits: no event is dropped, however many a test pushes.
- Home Assistant itself: a service call is recorded, and no state changes unless the test pushes it.
