---
title: Guest mode switch
description: Add a guest mode switch to Home Assistant that warms the guest room and that your other apps can read.
sidebar:
  order: 8
---

Creates a **Guest mode** switch in Home Assistant. Turning it on sets the guest room's heating to comfort, and saves
the mode where every other app can read it, so they can behave differently while guests stay.

**You need** the [Stewart integration](../guides/exposed-entities.md#setting-it-up) in Home Assistant, a thermostat
with presets, and persistence.

```php file=../../examples/apps/GuestMode.php
```

Any other app reads the mode from the shared store:

```php
public function __construct(#[GlobalStore] private ReadableStore $house) {}

// in a handler
if ($this->house->getBool('guest_mode') === true) {
    return;
}
```

## How it works

- `exposeSwitch()` creates the switch. After a restart, Home Assistant restores its last state, which the app copies
  into the store at start.
- When someone flips the switch, the handler writes the mode and sets the preset. Returning from the handler accepts
  the command, and the switch shows the new state.
- `#[GlobalStore]` is the store all apps share; other apps read it with a `ReadableStore`.

## Test

```php file=../../examples/tests/GuestModeTest.php
```

## Make it yours

- Turn guest mode off on its own after the guests leave: schedule `runAfter(Duration::hours(72), …)` when it turns on.
- Use it in the [motion light](motion-light.md) to keep the guest room's light dim.
