---
title: Door left open
description: Get a reminder about an open door after a few minutes, and again every few minutes until it closes.
sidebar:
  order: 4
---

Five minutes after the back door opens, you get a notification. Every ten minutes after that, you get another one,
until someone closes the door.

**You need** a door sensor and a working `notify.notify` service (for example the Home Assistant app on your phone).

```php file=../../examples/apps/DoorReminder.php
```

```yaml
apps:
  door-reminder:
    options:
      door: binary_sensor.back_door
      firstAfterMinutes: 5
      repeatEveryMinutes: 10
```

## How it works

- `whenChangedTo('on', for: …)` sends the first reminder once the door has been open for 5 minutes.
- `runEvery()` then repeats it. The app keeps the returned `ScheduledTask`, so it can `cancel()` it when the door
  closes.
- The message uses the door's friendly name from Home Assistant.

## Test

```php file=../../examples/tests/DoorReminderTest.php
```

## Make it yours

- Several doors: watch `binary_sensor.*_door`, and keep one task per entity ID in an array.
- Send it to one phone: set the service to `notify.mobile_app_<your phone>`.
