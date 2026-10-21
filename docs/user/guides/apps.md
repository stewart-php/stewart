---
title: Anatomy of an app
description: How Stewart finds an app, what it can ask for in its constructor, and how options and your own services reach it.
sidebar:
  order: 2
---

An app is a PHP class in `apps/` that implements `App` and carries `#[Automation]`. This page covers what goes into
one. The example warns you about low batteries, but not at night:

```php file=../../examples/apps/BatteryAlert.php
```

## The ID

`#[Automation(id: 'battery-alert')]` gives the app its ID. Stewart finds apps by this attribute, so there is no list
of apps to keep up to date.

The ID names the app in logs, in `stewart.yaml`, in environment variables, and in the data the app stores. Renaming
the class keeps the same app; changing the ID makes it a new one.

An ID starts with a lowercase letter and contains only lowercase letters, digits, `-` and `_`. Environment variables
cannot tell `-` from `_`, so `battery-alert` and `battery_alert` count as the same ID, and only one of them can exist.

## Starting and stopping

`initialize()` runs once, when the app starts. Use it to start watching entities or events and to add schedules, then return.
Stewart delivers nothing to the app until `initialize()` returns, so do not wait for anything in it.

Subscriptions and schedules end on their own when the app stops, so most apps need nothing more. An app that opens
something itself, such as a file, also implements `Stewart\Contracts\Disposable`, whose `dispose()` runs when the
app stops:

```php file=../../examples/apps/EnergyLog.php
```

Before Stewart 1.5, `App` declared `dispose()` too, so older apps have an empty one. Delete it, or add
`implements Disposable` when it does something. Until Stewart 2.0, a `dispose()` without `Disposable` still runs, and
`stewart check` names the app.

## What the constructor can ask for

Stewart fills each constructor parameter by its type:

| Type | What you get |
|---|---|
| `HaContext` | Home Assistant: states, state changes, events, triggers, service calls. See [State changes](state-changes.md) |
| `Psr\Log\LoggerInterface` | A logger whose lines carry the app's ID |
| `Stewart\Contracts\Schedule\Scheduler` | Schedules: daily, every few minutes, at sunset. See [Scheduling and waiting](scheduling.md) |
| `Stewart\Contracts\Store\Store` | Storage that survives restarts, for this app only. See [Persistence](persistence.md) |
| `Stewart\Contracts\Time\Clock` | The current time in Home Assistant's time zone, and `isWithin('22:00', '07:00')` |
| `Stewart\Contracts\Time\Timers` | Low-level timers; prefer schedules and waits |
| `Stewart\Contracts\Sun\SunCalendar` | Sunrise, sunset and the sun's position. See [Sun](sun.md) |
| `Stewart\Contracts\Mqtt\Mqtt` | An MQTT client. See [MQTT](mqtt.md) |
| `Stewart\Contracts\Exposure\EntityExposure` | Entities your app creates in Home Assistant. See [Exposed entities](exposed-entities.md) |
| `Stewart\Contracts\Registry\RegistryEditor` | Changes to Home Assistant's entity registry. See [Areas, floors and labels](registry.md) |
| `App\Generated\Entities`, `App\Generated\Services` | Typed classes for your entities. See [Typed entities](codegen.md) |
| A class from `services.php` | Your own services; see below |

Parameters with a scalar or array type (`int`, `string`, `array`, …) are **options**.

## Options

Options let you change an app without editing its code. Set them in `stewart.yaml`, under the app's ID:

```yaml
apps:
  battery-alert:
    options:
      threshold: 15
      notifyService: mobile_app_my_phone
```

- Each key under `options` is the name of a constructor parameter, and its value replaces that parameter's default.
- A parameter without a default must be set, or the app does not start.
- An option that matches no parameter is an error that names the app and the class.
- Options can also come from the environment: `STEWART_APPS__BATTERY_ALERT__OPTIONS__THRESHOLD=15`. See
  [Configuration](configuration.md).

Next to `options`, an app's entry takes three more settings:

```yaml
apps:
  battery-alert:
    enabled: false   # do not load the app at all
    paused: true     # load it, but deliver nothing to it until it is resumed
    worker: 1        # run it on this worker
```

If an entry names an ID that no app has, Stewart logs a warning at start and ignores the entry, so a typo does not go
unnoticed. `stewart check` prints the same warning and still passes.

## Your own classes (services.php)

Code that several apps share, or that holds a connection to something, goes in a class of its own, called a
**service**. `services.php` is not related to Home Assistant services; it tells Stewart which of your classes it may
pass to apps. `QuietHours` from the example is one:

```php file=../../examples/apps/Support/QuietHours.php
```

Register it in `services.php`:

```php
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire();
    $services->set(App\Support\QuietHours::class);
};
```

Any app can now ask for `QuietHours` in its constructor. A service can ask for the same types an app can, such as
`Clock` or `LoggerInterface`. To register every class in a folder at once, use
`$services->load('App\\Support\\', __DIR__ . '/apps/Support/');`.

Each worker process loads `services.php`, so register services there but do no work in it. Apps never go in
`services.php`; Stewart finds those by their attribute.

## Testing an app

The test for the example provides its own `QuietHours` and moves the clock to check both day and night:

```php file=../../examples/tests/BatteryAlertTest.php
```

[Testing apps](testing.md) covers the harness.

## Common problems

**`matches no parameter of …::__construct()`.** An option name in `stewart.yaml` does not match a constructor
parameter. Compare the spelling.

**`needs option "…"`.** A constructor parameter has no default and no option sets it.

**The app does not run.** At start, Stewart logs a warning for a class in `apps/` that implements `App` without
`#[Automation]`, and an error for a file in `apps/` that fails to load. Check those lines first.
