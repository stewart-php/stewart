---
title: Motion light
description: Turn a light on with motion after dark, dimmed late at night, and off a few minutes after the motion stops.
sidebar:
  order: 2
---

Turns the hall light on when someone walks by after sunset. Late at night it is dimmed, so it does not wake anyone.
A few minutes after the motion stops, it turns off again.

**You need** a motion sensor and a dimmable light.

```php file=../../examples/apps/NightMotionLight.php
```

```yaml
apps:
  night-motion-light:
    options:
      motionSensor: binary_sensor.hall_motion
      light: light.hall
      offAfterMinutes: 3
      nightBrightness: 10
```

## How it works

- `filter(fn () => !$this->sun->isSunUp())` lets motion through only while the sun is down.
- `Clock::isWithin('23:00', '06:00')` picks the brightness. The window may cross midnight.
- `whenChangedTo('off', for: …)` turns the light off only once the sensor has reported no motion for the whole time.
  New motion starts the wait again.
- The light is only turned off if it is still on, so a light someone already switched off is left alone.

## Test

```php file=../../examples/tests/NightMotionLightTest.php
```

## Make it yours

- Skip the dimming when someone is watching TV: add a check of `media_player.living_room` in the handler.
- Leave a light alone after someone switched it by hand: see [Your own changes](../guides/events.md#your-own-changes-or-someone-elses).
- Several rooms: an app runs once, so copy the class under another name and ID, or watch an
  [area filter](../guides/registry.md) and turn on the lights of the area where the motion was.
