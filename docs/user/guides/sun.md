---
title: The sun
description: Run at sunrise, sunset, dusk or golden hour, and ask where the sun is, at Home Assistant's location.
sidebar:
  order: 18
---

Stewart knows the sun from the latitude, longitude and elevation configured in Home Assistant. Times are computed in
the worker, so they keep coming while Home Assistant is unreachable, and agree with Home Assistant's sun integration
within seconds.

## Scheduling

```php
use Stewart\Contracts\Sun\SunEvent;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\SunOffset;

$this->scheduler->runAtSunEvent(SunEvent::Sunrise, $this->openBlinds(...));
$this->scheduler->runAtSunEvent(SunEvent::Sunset, $this->lightsOn(...), SunOffset::before(Duration::minutes(30)));
$this->scheduler->runAtSunEvent(SunEvent::GoldenHourEveningStart, $this->warmLights(...));
```

These are ordinary `ScheduledTask`s: `getNextRunAt()`, `cancel()`, missed runs after a suspend, torn down with your
app. On a day the event does not happen, such as sunset during the midnight sun, the schedule waits for the next day
it does.

## Asking

Inject `Stewart\Contracts\Sun\SunCalendar`:

```php
$this->sun->isSunUp();
$this->sun->findNextEvent(SunEvent::CivilDusk);
$this->sun->findNextEvent(SunEvent::Sunrise, SunOffset::after(Duration::minutes(20)));
$this->sun->getDayOn(new DateTimeImmutable('tomorrow'))->findEventTime(SunEvent::Sunset);
$this->sun->getCurrentPosition()->azimuthDegrees;
```

| `SunEvent` | Sun's apparent elevation |
|---|---|
| `AstronomicalDawn` / `AstronomicalDusk` | −18° |
| `NauticalDawn` / `NauticalDusk` | −12° |
| `CivilDawn` / `CivilDusk`, `BlueHourMorningStart` / `BlueHourEveningEnd` | −6° |
| `BlueHourMorningEnd` / `BlueHourEveningStart` | −4° |
| `Sunrise` / `Sunset` | upper edge on the horizon |
| `GoldenHourMorningEnd` / `GoldenHourEveningStart` | +6° |
| `SolarNoon` | highest point |

Golden hour runs from −4° to +6°, blue hour from −6° to −4°.

`SunPosition` elevation includes atmospheric refraction, like the `elevation` attribute of `sun.sun`. For shading,
ask whether the sun stands in front of a facade and high enough to matter; the sector may wrap past north:

```php
$position = $this->sun->getCurrentPosition();

if ($position->isWithinAzimuth(135.0, 225.0) && $position->isHigherThan(15.0)) {
    $this->closeSouthBlinds();
}
```

Whether the sun is up is `isSunUp()`, which accounts for the observer's elevation exactly as sunrise and sunset do.

Without a location in Home Assistant, every `SunCalendar` query throws `SunException` and `runAtSunEvent()` throws
`ScheduleException`. A location changed in Home Assistant is picked up on the next restart.

For Home Assistant's own sun trigger, use [`watchTrigger(HaTrigger::onSunset())`](triggers.md).
