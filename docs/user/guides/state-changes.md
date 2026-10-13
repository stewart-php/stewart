---
title: State changes
description: Read entity states, react when they change, and shape the changes with operators such as for, debounce and thresholds.
sidebar:
  order: 3
---

Most automations follow one pattern: when an entity changes, do something. This app turns the hall light on when
motion starts, and off two minutes after it stops:

```php file=../../examples/apps/HallMotionLight.php
```

`watchStateChanges()` returns a **stream** of state changes. **Operators** such as `whenChangedTo()` shape it, and
`subscribe()` runs your function for each change that comes through.

## Reading the current state

`HaContext` reads states from Stewart's own copy, so these calls return at once and you can make them as often as you
like:

| Method | Returns |
|---|---|
| `getState('light.hall')` | The light's `EntityState`, or `null` if it does not exist |
| `requireState('light.hall')` | The same, but throws when the entity does not exist |
| `listStates('sensor.*_battery')` | Every matching entity |

An `EntityState` has the state as text (`$state->state`, for example `'on'` or `'21.5'`), its attributes
(`getAttribute('brightness')`, `getFloatAttribute()`, …), `getStateAsFloat()`, `getFriendlyName()`, and when it
last changed (`lastChangedAt`).

To check how long an entity has held its state, pass a `Clock` (see [Anatomy of an app](apps.md)):
`$state->hasHeldFor(Duration::minutes(10), $this->clock)`.

## Choosing entities

Wherever a method takes entities, you can pass:

- an exact ID: `'light.hall'`
- a pattern with `*`: `'sensor.*_battery'`
- a list: `['light.hall', 'light.porch']`, or `Selector::anyOf('light.hall', 'light.porch')`
- a regular expression, only when you ask for one: `Selector::regex('#^light\.(hall|porch)$#')`
- an area, floor, label or device: `EntityFilter::inArea('kitchen')->withDomain('light')`. See
  [Areas, floors and labels](registry.md)

Patterns also match entities added later.

## Reacting to changes

Each `StateChange` holds the entity ID and the state before (`$change->from`) and after (`$change->to`). Either can be
`null`, when the entity was just added or removed.

```php
$this->ha->watchStateChanges('sensor.*_temperature')->subscribe(function (StateChange $change): void {
    $this->logger->info("{$change->entityId->value} is now {$change->to?->state}");
});
```

## Operators

Operators sit between `watchStateChanges()` and `subscribe()`, and each one returns a new stream.

| Operator | Lets through |
|---|---|
| `whenChangedTo('on')` | Changes to that state |
| `whenChangedTo('on', for: Duration::minutes(5))` | The same, once the entity has stayed in it for 5 minutes |
| `whenChangedTo('on', from: 'off')` | Only changes from those states (a string or a list) |
| `whenStableFor(Duration::seconds(30))` | Any state, once the entity has stayed in it that long |
| `whenAbove(25.0)` / `whenBelow(10.0)` | A number crossing the threshold; see below |
| `distinctUntilChanged()` | Only changes of the state itself, not of attributes |
| `startWithCurrentState()` | First the current state of each entity, then the changes |
| `filter(fn ($change) => …)` | The changes your function returns `true` for |
| `debounce(Duration::seconds(2))` | The last change once the entity has been quiet for 2 seconds |
| `throttle(Duration::minutes(1))` | At most one change a minute |
| `take(3)` / `takeUntil($otherStream)` | The first 3 changes / changes until another stream fires |
| `map(fn ($change) => …)` | Your function's result instead of the change |
| `acrossEntities()` | One stream for all entities; see below |

Each operator works **per entity**. `watchStateChanges('light.*')->debounce(...)` debounces each light on its own.
After `acrossEntities()`, `debounce` and `throttle` treat all entities as one.

### whenChangedTo

`whenChangedTo('on', for: …, from: …)` works like the state trigger in Home Assistant:

```yaml
trigger:
  platform: state
  to: 'on'
  from: 'off'
  for: '00:05:00'
```

It ignores a change *from* `unknown` or `unavailable`, so a Home Assistant restart or a device that rejoins the
network does not fire it. To accept those too, pass `fromAnyState: true`.

### whenAbove and whenBelow

`whenAbove(25.0)` fires when the value moves from 25 or less to above 25, then waits until the value has gone back
before it fires again. For a sensor that hovers around the line, add `hysteresis: 0.5`: the value must then fall to
24.5 or less to count as back. `attribute: 'temperature'` reads an attribute instead of the state, and `for:` asks for
the value to stay above the line for that long.

The first reading only sets the starting point. A crossing counts from a known number, so a sensor that is
`unavailable` and then reports 30 does not fire.

### Put time windows first

`for:`, `whenStableFor()` and `debounce()` need to see the entity *leave* a state to know it did not stay. A filter
in front of them can hide that:

```php
// Wrong: whenChangedTo('on') drops the 'off', so this fires 3 seconds
// after motion starts, even if it stopped after 1.
->whenChangedTo('on')->whenStableFor(Duration::seconds(3))

// Right: one operator that also sees the 'off'.
->whenChangedTo('on', for: Duration::seconds(3))
```

### Starting with the current state

`startWithCurrentState()` sends each entity's current state first, then the changes. Use it when the app should act on
the state at start, not only on the next change. Put it right after `watchStateChanges()`, so the other operators see
those first states too. A first state is not a change, so `whenChangedTo('on')` ignores a light that is already on.

## After a lost connection

When the connection to Home Assistant drops and comes back, Stewart compares the states and sends the differences as
changes. `$change->isReconstructed()` is `true` for those. `$this->ha->isConnected()` tells whether Stewart is
connected right now, and `watchConnection()` is a stream of `ConnectionLost` and `ConnectionRestored`.

## Testing

```php file=../../examples/tests/HallMotionLightTest.php
```

Seed each entity's state before the app starts. A first state is not a change, so `whenChangedTo('on')` would ignore
a state pushed for an entity it has never seen.

## Common problems

**The handler never runs.** Check the entity ID in Home Assistant under **Developer tools → States**. A pattern such
as `light.*` must match the whole ID.

**`for:` never fires.** Any change to another state, even a short one, starts the window again. Attribute updates do
not.

**It fires right after Home Assistant restarts.** Use `whenChangedTo()`, which ignores changes from `unavailable`,
rather than a `filter()` on the new state.
