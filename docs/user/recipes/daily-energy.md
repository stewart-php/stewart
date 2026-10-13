---
title: Daily energy summary
description: Every evening, send today's energy use and the total of the last seven days.
sidebar:
  order: 6
---

At 23:55, reads today's energy use, stores it for a week, and sends a notification with today's value and the
seven-day total.

**You need** a sensor with today's energy in kWh (from the Energy dashboard, a utility meter, or your inverter), and
[persistence](../guides/persistence.md), which projects from the skeleton have.

```php file=../../examples/apps/DailyEnergy.php
```

```yaml
apps:
  daily-energy:
    options:
      meter: sensor.energy_today
```

## How it works

- Each day's value is stored under a key with the date, such as `day:2026-01-01`, with a time limit of seven days.
  Older days disappear on their own.
- The week's total adds up the last seven keys; a missing day counts as 0.
- The dates come from the `Clock`, in Home Assistant's time zone.

## Test

```php file=../../examples/tests/DailyEnergyTest.php
```

## Make it yours

- Compare with last week: keep 14 days and add up both halves.
- Put the week's total in Home Assistant: expose it as a [sensor](../guides/exposed-entities.md).
