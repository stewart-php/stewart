---
title: Blinds against the sun
description: Close the blinds when the sun shines on the window on a hot day, and open them again at sunset.
sidebar:
  order: 5
---

Every ten minutes, checks whether the sun stands in front of the south facade and it is hot outside. If so, it closes
the blinds. At sunset it opens them again.

**You need** a cover for the blinds, an outdoor temperature sensor, and a location set in Home Assistant.

```php file=../../examples/apps/SunBlinds.php
```

```yaml
apps:
  sun-blinds:
    options:
      blinds: cover.south_blinds
      outsideTemperature: sensor.outside_temperature
      closeAbove: 24
      facadeFrom: 135   # compass degrees the window faces, from…
      facadeTo: 225     # …to
```

## How it works

- `getCurrentPosition()` gives the sun's compass direction and height, computed from Home Assistant's location.
  `isWithinAzimuth()` checks the direction, and `isHigherThan(10.0)` ignores a sun low behind the neighbours.
- The blinds only move when they are `open` or `closed`, so the app does not fight someone who set them by hand.
- `runAtSunEvent(SunEvent::Sunset, …)` opens them in the evening.

## Test

```php file=../../examples/tests/SunBlindsTest.php
```

## Make it yours

- Find your facade's direction with a compass app: east is 90°, south 180°, west 270°.
- Close them only partly: call `cover.set_cover_position` with a `position`.
