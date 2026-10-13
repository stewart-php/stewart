---
title: Sun
description: Run code at sunrise, sunset, dusk or golden hour, and ask where the sun stands, at Home Assistant's location.
sidebar:
  order: 13
---

Stewart computes the sun's times and position from the location set in Home Assistant (**Settings → System →
General**). The results agree with Home Assistant's own sun integration within seconds, and keep coming while Home
Assistant is unreachable. This app closes the south-facing blinds while the sun shines on them:

```php file=../../examples/apps/SouthBlinds.php
```

## Running code at a sun event

```php
$this->scheduler->runAtSunEvent(SunEvent::Sunrise, $this->openBlinds(...));
$this->scheduler->runAtSunEvent(SunEvent::Sunset, $this->lightsOn(...), SunOffset::before(Duration::minutes(30)));
$this->scheduler->runAtSunEvent(SunEvent::GoldenHourEveningStart, $this->warmLights(...));
```

These are ordinary schedules; see [Scheduling and waiting](scheduling.md). On a day an event does not happen, such as
sunset during the midnight sun, the schedule waits for the next day it does.

| `SunEvent` | The sun's height |
|---|---|
| `AstronomicalDawn` / `AstronomicalDusk` | −18° |
| `NauticalDawn` / `NauticalDusk` | −12° |
| `CivilDawn` / `CivilDusk` | −6° |
| `BlueHourMorningStart` / `BlueHourEveningEnd` | −6° |
| `BlueHourMorningEnd` / `BlueHourEveningStart` | −4° |
| `Sunrise` / `Sunset` | Top edge on the horizon |
| `GoldenHourMorningEnd` / `GoldenHourEveningStart` | +6° |
| `SolarNoon` | Highest point of the day |

Golden hour runs from −4° to +6°, blue hour from −6° to −4°.

## Asking about the sun

Ask for `SunCalendar` in the constructor:

| Method | Returns |
|---|---|
| `isSunUp()` | Whether the sun is up now |
| `findNextEvent(SunEvent::CivilDusk)` | When the event next happens, as an `Instant` |
| `findNextEvent(SunEvent::Sunrise, SunOffset::after(Duration::minutes(20)))` | The same, shifted |
| `getDayOn(new DateTimeImmutable('tomorrow'))->findEventTime(SunEvent::Sunset)` | An event on a given day |
| `getCurrentPosition()` | Where the sun stands now |
| `getPositionAt($instant)` | Where it stands at another moment |

A `SunPosition` has `azimuthDegrees` (0° is north, 90° east) and `elevationDegrees` (above the horizon, including
refraction, like the `elevation` attribute of `sun.sun`). `isWithinAzimuth(135.0, 225.0)` checks a compass sector,
also one that wraps past north, and `isHigherThan(15.0)` the height.

## Testing

The test harness's sun rises at 06:00, stands in the south at 45° all day, and sets at 18:00.
`$harness->sunCalendar->stubEventTime(SunEvent::Sunset, '16:30')` moves an event.

## Common problems

**`A sunset schedule needs a location`, or a `SunException`.** Home Assistant has no location. Set it under **Settings
→ System → General** and restart Stewart. Stewart also picks up a changed location only at restart.
