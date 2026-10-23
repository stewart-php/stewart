---
title: Your first app
description: Build an automation that turns the porch light on at sunset, test it, and run it.
sidebar:
  order: 2
---

On this page you build an app that turns the porch light on at sunset and off at bedtime. You test it without
waiting for the sun, then run it against your Home Assistant.

You need a project from [Install Stewart](install.md).

## 1. Write the app

`make app NAME=PorchLight` creates `apps/PorchLight.php` and a test for it in `tests/`. Replace the app with:

```php file=../../examples/apps/PorchLight.php
```

Here is what each part does:

- **`#[Automation(id: 'porch-light')]`** marks the class as an app. Stewart finds every app in `apps/` by this
  attribute. The ID names the app in logs, in `stewart.yaml` and in stored data, so keep it once the app runs. It
  starts with a lowercase letter and uses only lowercase letters, digits, `-` and `_`.
- **`implements App`** gives the app its lifecycle method: `initialize()` runs once, when the app starts. What it
  sets up ends on its own when the app stops.
- **The constructor** asks for what the app needs, and Stewart passes it in:
  - `HaContext` talks to Home Assistant: reads states, watches changes, calls actions.
  - `Scheduler` runs code at a time of day, at a sun event, or every few minutes.
  - `LoggerInterface` writes to Stewart's log, tagged with the app's ID.
  - `$light` and `$offAt` are **options**. Their defaults apply unless `stewart.yaml` sets them.
- **`initialize()`** sets up two schedules. At sunset, the app calls the `light.turn_on` service on the porch light.
  At `$offAt`, it calls `light.turn_off`. Both log a line first, so you can see them happen.

Stewart computes sunset from the location set in Home Assistant (**Settings → System → General**), so the schedule
keeps working even while Home Assistant is restarting.

## 2. Configure it

Your porch light probably has another entity ID. Find it in Home Assistant under **Settings → Devices & services →
Entities**, then set it in `stewart.yaml`:

```yaml
apps:
  porch-light:
    options:
      light: light.front_porch
      offAt: '22:30'
```

The keys under `options` are the names of the constructor parameters. Each one replaces that parameter's default.

## 3. Test it

Waiting for sunset to check your app is slow. A test runs the app on a virtual clock instead. Replace
`tests/PorchLightTest.php` with:

```php file=../../examples/tests/PorchLightTest.php
```

- `AppHarness` builds the app the way Stewart does, but with fake versions of Home Assistant, the clock and the
  scheduler.
- Its clock starts at midnight on 1 January 2026 and stands still until the test moves it.
- `advanceTime(Duration::hours(18))` jumps to 18:00, the harness's sunset. Every schedule due on the way runs.
- `getLastCall()` returns the last service call the app made, so the test can check it.

Run the tests:

```bash
make test
```

[Testing apps](../guides/testing.md) covers the harness in full.

## 4. Try it without touching your lights

`DRY_RUN=1` runs Stewart with your real Home Assistant, but logs each service call instead of sending it. `ONLY`
runs just the apps you name:

```bash
make run ONLY=porch-light DRY_RUN=1
```

Stewart warns that dry run is on. When a schedule fires, you see the app's log line, followed by
`Service call not sent: service_calls.dry_run is on` with the service and the target.

While you work on the app, use `make watch` instead of `make run`, with the same `ONLY` and `DRY_RUN`. Every time you
save a file in `apps/`, `services.php` or `stewart.yaml`, it checks the project and restarts the apps on the new code,
without reconnecting to Home Assistant. A save that breaks something keeps the apps running as they were and logs
why.

## 5. Run it for real

```bash
make run ONLY=porch-light
```

Leave out `ONLY` to run every app. To keep Stewart running in the background, use `make up`, then follow its log
with `make logs` and stop it with `make down`. For a server, see
[Deploying](../deploy/index.md).

You no longer need the example apps. Delete `apps/HelloApp.php`, `apps/ChangeCounter.php`, their tests in `tests/`
and their `hello:` and `change-counter:` entries in `stewart.yaml`.

## Common problems

**The app logs `A sunset schedule needs a location, and Home Assistant reported none`.** Home Assistant has no
location. Set one under **Settings →
System → General** and restart Stewart.

**`App "porch-light" sets option "…", which matches no parameter of App\PorchLight::__construct()`.** An option
in `stewart.yaml` has a name the constructor does not have. Check its spelling against the parameter names.

**The light does not react.** Run with `DRY_RUN=1` and check that the log shows the right entity ID. Then call
the same service in Home Assistant under **Developer tools → Actions** to rule out the light itself.

## Next

- [Project tour](project-tour.md) explains the other files in your project.
- [How it works](../guides/how-it-works.md) explains the rules every app follows, such as never calling `sleep()`.
- [State changes](../guides/state-changes.md) shows how to react when an entity changes.
