---
title: Heating by presence
description: Lower the heating when everyone has left home, and raise it again when someone comes back.
sidebar:
  order: 3
---

Sets the thermostats to `away` once everyone has been gone for 15 minutes, and back to `home` as soon as someone
returns. A quick trip to the shop changes nothing.

**You need** a group or person that is `home` or `not_home` (such as a `group` of `person` entities), and thermostats
with `away` and `home` presets.

```php file=../../examples/apps/PresenceHeating.php
```

```yaml
apps:
  presence-heating:
    options:
      household: group.family
      thermostats: [climate.living_room, climate.bedroom, climate.office]
      awayAfterMinutes: 15
```

## How it works

- `whenChangedTo('not_home', for: …)` fires only after the household has been away for the whole time.
- `whenChangedTo('home', from: 'not_home')` fires on the way back, but not when Home Assistant restarts and the
  group goes from `unknown` to `home`.
- One action call targets every thermostat at once.

## Test

```php file=../../examples/tests/PresenceHeatingTest.php
```

## Make it yours

- Thermostats without presets: call `climate.set_temperature` with a `temperature` instead.
- Heat up before you arrive: use a zone [trigger](../guides/triggers.md) for "entering a zone around home".
