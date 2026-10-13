---
title: Coming from other tools
description: How Home Assistant YAML automations, AppDaemon and NetDaemon map to Stewart.
sidebar:
  order: 5
---

If you have written automations before, most ideas carry over. This page maps the common ones.

## Home Assistant automations (YAML)

| YAML | Stewart |
|---|---|
| `trigger: platform: state, to: 'on'` | `watchStateChanges('…')->whenChangedTo('on')` |
| `from:`, `for:` | `whenChangedTo('on', from: 'off', for: Duration::minutes(5))` |
| `trigger: platform: numeric_state, above: 25` | `->whenAbove(25.0)` |
| `trigger: platform: time, at: '07:00'` | `$scheduler->runDailyAt('07:00', …)` |
| `trigger: platform: sun, event: sunset, offset: '-00:30:00'` | `runAtSunEvent(SunEvent::Sunset, …, SunOffset::before(Duration::minutes(30)))` |
| `trigger: platform: event` | `watchEvents('…')` |
| Any other trigger | `watchTrigger([...])` with the same keys |
| `condition: state` | `if ($this->ha->getState('…')?->state === '…')` |
| `condition: time, after/before` | `$clock->isWithin('22:00', '06:00')` |
| `action: light.turn_on, target: entity_id` | `callService('light', 'turn_on', target: ServiceTarget::forEntities('…'))` |
| `delay:` | A schedule (`runAfter()`), or `whenChangedTo(…, for: …)` |
| `wait_for_trigger:` | `waitForState()` or `$stream->waitForNext()` |
| `mode: single` | Schedules never overlap; a subscription handles its events one at a time |
| Blueprint inputs | App [options](../guides/apps.md#options) |

What you gain: tests, Git history, refactoring, shared code between automations, and an editor that completes your
entity IDs.

## AppDaemon

| AppDaemon | Stewart |
|---|---|
| A class with `initialize()` | A class with `#[Automation]` and `initialize()` |
| `apps.yaml` arguments, `self.args` | Constructor parameters set as options in `stewart.yaml` |
| `self.listen_state(cb, entity, new='on')` | `watchStateChanges($entity)->whenChangedTo('on')->subscribe(…)` |
| `duration=` | `for:` |
| `self.listen_event(cb, 'zha_event')` | `watchEvents('zha_event')->subscribe(…)` |
| `self.get_state(entity)` | `$this->ha->getState($entity)?->state` |
| `self.call_service('light/turn_on', entity_id=…)` | `callService('light', 'turn_on', target: ServiceTarget::forEntities(…))` |
| `self.run_daily(cb, time)` | `runDailyAt('07:00', …)` |
| `self.run_in(cb, 60)` | `runAfter(Duration::seconds(60), …)` |
| `self.run_every(cb, start, 300)` | `runEvery(Duration::minutes(5), …)` |
| `self.run_at_sunset(cb)` | `runAtSunEvent(SunEvent::Sunset, …)` |
| Global variables between apps | The [global store](../guides/persistence.md#storage-all-apps-share), or `publish()` and `watchTopic()` |

Like AppDaemon, Stewart runs apps outside Home Assistant. Unlike it, apps run in several worker processes, so a crash in
one worker does not stop apps on the others, and the [test harness](../guides/testing.md) runs apps without Home
Assistant.

## NetDaemon

| NetDaemon | Stewart |
|---|---|
| A class with `[NetDaemonApp]` | A class with `#[Automation]` |
| Generated `Entities` and `Services` | Generated `Entities` and `Services` from `make generate`; see [Typed entities](../guides/codegen.md) |
| `entity.StateChanges().Where(…).Subscribe(…)` | `->watchStateChanges()->filter(…)->subscribe(…)` |
| `.WhenStateIsFor(…, TimeSpan)` | `whenChangedTo(…, for: Duration::…)` |
| `.Throttle(…)` | `->throttle(Duration::…)` |
| `IScheduler.RunEvery(…)`, `RunIn(…)` | `runEvery(…)`, `runAfter(…)` |
| App configuration classes | Constructor options in `stewart.yaml` |
| Dependency injection | Constructor injection, and `services.php` for your own services |

Both generate typed classes from your instance and use streams of state changes. Stewart uses PHP instead of C#, and
runs each worker as its own process.
