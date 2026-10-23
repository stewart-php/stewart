---
title: Scheduling and waiting
description: Run code at a time of day, on weekdays, at sunset or every few minutes, and wait for an entity in the middle of a handler.
sidebar:
  order: 5
---

The `Scheduler` runs code at set times. Ask for it in the constructor. This app starts the coffee machine at 06:45 on
weekdays, and closes the living room covers half an hour before sunset:

```php file=../../examples/apps/WeekdayMorning.php
```

## Schedules

| Method | Runs |
|---|---|
| `runDailyAt('07:00', $handler)` | Every day at 07:00 |
| `runDailyAt('07:00', $handler, DayOfWeek::Saturday, DayOfWeek::Sunday)` | Only on the days you list |
| `runEvery(Duration::minutes(5), $handler)` | Every 5 minutes, starting 5 minutes after the app starts |
| `runOnCron('0 22 * * *', $handler)` | On a standard five-field cron expression |
| `runAtSunEvent(SunEvent::Sunset, $handler)` | Every day at a sun event: sunrise, sunset, dawn, dusk, golden hour, … |
| `runAtSunEvent(SunEvent::Sunrise, $handler, SunOffset::after(Duration::minutes(20)))` | The same, shifted before or after |
| `runAtEntityTime('input_datetime.wake_up', $handler)` | At the time an `input_datetime` or a timestamp sensor holds, following its changes |
| `runAfter(Duration::minutes(20), $handler)` | Once, after 20 minutes |
| `runAt($moment, $handler)` | Once, at a `DateTimeImmutable` |

Times are `'07:00'` or `'07:00:30'`. Each method returns a `ScheduledTask`; call `cancel()` on it to stop it. All
schedules stop when the app stops, so there is nothing to clean up.

The handler may take a `ScheduledRun`, which tells it when it was due (`scheduledFor`), how late it started
(`getLateness()`) and how many runs it missed (`missedOccurrences`). Most handlers do not need it.

## How schedules treat time

**Times follow Home Assistant's time zone**, not the container's. A 07:00 schedule runs when Home Assistant says it is
07:00.

**Daylight saving time is handled.** On the night the clocks go forward, a time that does not exist that night (for
example 02:30) does not run. On the night they go back, a time that happens twice runs once. A cron that runs every 15
minutes pauses during the repeated hour; use `runEvery()` for work that must not pause.

**`runEvery()` and `runAfter()` count elapsed time.** A clock correction does not make them run early or late. The
other methods follow the wall clock.

**Missed runs are skipped, not caught up.** If Stewart was down at 03:00, the 03:00 run does not happen at 09:00.

**Runs never overlap.** If a handler is still running when its next run is due, that run is skipped.

**Sun events need a location** in Home Assistant (**Settings → System → General**). Stewart computes them itself, so
they keep working while Home Assistant restarts. See [Sun](sun.md).

## Waiting in a handler

Some automations are a sequence of steps: lock the door, then check that it locked. `waitForState()` pauses the
handler until an entity reaches a state:

```php file=../../examples/apps/NightLock.php
```

| Method | Waits for |
|---|---|
| `$this->ha->waitForState('lock.front_door', 'locked', Duration::seconds(30))` | The entity to be in that state; returns at once if it already is |
| `$stream->waitForNext(Duration::minutes(1))` | The next item of a stream, after its operators |
| `$this->scheduler->waitFor($arm, Duration::minutes(1))` | Anything else: `$arm` receives a `PendingWait`, starts what will call `$wait->resolve($value)`, and returns a closure that cleans up once the wait ends |

- **A timeout returns `null`.** Without a timeout, the wait lasts until it is met or the app stops.
- **Other apps keep running** while a handler waits. Later events for the *same* subscription wait their turn.
- **Do not wait in `initialize()`.** The app receives nothing until `initialize()` returns, so the wait could only time
  out. Add a schedule or a subscription in `initialize()`, and wait in its handler.
- **Do not use `sleep()` or any other function that pauses PHP.** It holds up every app on the worker, and tests
  cannot skip it. Use a schedule, a
  wait, or an operator such as `whenChangedTo(…, for: …)`.

For "has stayed off for 5 minutes", use `whenChangedTo('off', for: Duration::minutes(5))` from
[State changes](state-changes.md). Waits are for steps that follow one another.

## Testing

Tests run schedules on a virtual clock. `advanceTime()` moves it, and every schedule due on the way runs:

```php file=../../examples/tests/NightLockTest.php
```

The test harness's sun rises at 06:00 and sets at 18:00. `new AppHarness(new DateTimeImmutable('2026-01-03'))` starts
the clock on another day, which is handy for weekday schedules.

## Common problems

**`A sunset schedule needs a location`.** Home Assistant has no location set.

**A schedule runs at the wrong hour.** Check the time zone in Home Assistant under **Settings → System → General**.

**`runAt()` logs a warning and never runs.** The moment is already in the past.
