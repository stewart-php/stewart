---
title: Exposed entities
description: Create and drive your own sensor, switch, number, select and other entities in Home Assistant.
sidebar:
  order: 15
---

An app can create its own entities in Home Assistant: a sensor with a computed value, a switch that turns an
automation on and off, a number to tune it. This app publishes the average of two temperature sensors:

```php file=../../examples/apps/AverageTemperature.php
```

A new project also has `apps/ChangeCounter.php`, which exposes a sensor, a switch, a number and an action together;
turn it on in `stewart.yaml` once the integration below is installed.

## Setting it up

Exposed entities need the **Stewart integration** in Home Assistant, of the same version as Stewart:

1. In HACS, open the menu, choose **Custom repositories**, and add `https://github.com/stewart-php/hass-stewart` with
   the category **Integration**. Download **Stewart**. Without HACS, unpack `stewart.zip` from the
   [release](https://github.com/stewart-php/hass-stewart/releases) that matches your Stewart version into
   `config/custom_components/stewart/`.
2. Restart Home Assistant.
3. Open **Settings → Devices & services → Add integration**, pick **Stewart** and confirm.

Stewart finds the integration each time it connects; `make status` shows it on the `component` row. The integration
needs Home Assistant 2026.4 or newer, and Stewart's token must belong to an administrator.

## How an entity is identified

- The key (`average_temperature`) is the entity's identity: its unique ID is
  `stewart-<expose.instance>-<app id>-<key>`. Renaming the app or the key makes a
  new entity. Home Assistant picks the entity ID; `getEntityId()` returns it.
- Each app gets a device, `Stewart · <app id>`, under one hub device per daemon.
  Pass a `DeviceInfo` to put an entity on a device of its own.
- `exposeBinarySensor()` takes a `BinarySensorConfig`; its handle has `setOn()`
  and `setOff()`.
- A sensor value is a number, a string or `null` (unknown). With the `timestamp`
  or `date` device class, pass a `DateTimeInterface`.
- `setAttributes()`, `markAvailable()` and `markUnavailable()` change the rest of
  the state; `remove()` deletes the entity from Home Assistant.

## Switches and buttons

A switch or a button sends commands back when someone uses it in Home Assistant. Watch them with `watchCommands()`;
Home Assistant waits for your handler's answer. This switch refuses to turn the heater on while the window is open:

```php file=../../examples/apps/HeaterSwitch.php
```

A button works the same way: `exposeButton('boost')->watchCommands()` receives a `ButtonPress` for each press.

- When the handler returns, the command is accepted: the switch takes the
  requested state and `getValue()` returns it.
- `CommandException::rejected()` fails the service call with your message and
  leaves the state as it was. Any other exception fails it too, with a generic
  message, and counts as a handler failure.
- The handler may await, for example a service call. Commands for one entity
  run one at a time.
- A switch nobody watches accepts every command, so it works as a stored toggle
  your app reads with `getValue()`.
- A command a stream operator drops (`filter()`, `debounce()`, …) or `map()`s
  away is not answered; Home Assistant gives up after `expose.command_timeout`
  (default `10s`, at most `300s`).
- Commands for a paused app, or one that is not running, fail at once.
- `SwitchCommand::getContext()` tells who used the entity, like any other
  Home Assistant context.
- Home Assistant records a button's press time before your handler runs, so a
  rejected press still shows as the last press.

## Numbers, selects, texts, dates and times

These take a value from Home Assistant the same way a switch takes on and off:

```php
$offset = $this->entities->exposeNumber('target_offset', new NumberConfig(min: -3, max: 3, step: 0.5, unit: '°C', name: 'Target offset'));
$offset->watchCommands()->subscribe(fn(NumberCommand $command) => $this->shiftTarget($command->value));

$mode = $this->entities->exposeSelect('mode', new SelectConfig(['eco', 'comfort', 'away'], name: 'Mode'));
$mode->watchCommands()->subscribe(fn(SelectCommand $command) => $this->applyMode($command->option));
```

| Method | Config | Value | Command |
|---|---|---|---|
| `exposeNumber()` | `NumberConfig` (`min`, `max` required; `step`, `mode`, `deviceClass`, `unit`) | `int\|float` | `NumberCommand` |
| `exposeSelect()` | `SelectConfig` (`options` required) | `getOption()`, `setOption()` | `SelectCommand` |
| `exposeText()` | `TextConfig` (`min`, `max` up to 255, `pattern`, `mode`) | `string` | `TextCommand` |
| `exposeTime()` | `TimeConfig` | `TimeOfDay` | `TimeCommand` |
| `exposeDate()` | `DateConfig` | `DateTimeImmutable`, midnight UTC | `DateCommand` |
| `exposeDateTime()` | `DateTimeConfig` | `DateTimeImmutable` with its offset | `DateTimeCommand` |

Home Assistant checks values against the range, options and pattern, and
throws `StateInvalid` for one that does not fit. A text `pattern` is a Python
regular expression matched from the start; anchor it with `^…$` for a full
match.

## Actions

An action is a service your app answers, for scripts, automations and the action picker in Home Assistant. It has no
entity and no state:

```php file=../../examples/apps/BoostAction.php
```

- Home Assistant registers it as `stewart.<app>_<key>`, with `-` in the app ID
  as `_`: `stewart.heating_boost` above.
- Field types are `Text`, `Number`, `Boolean`, `Select` (with `options`) and
  `Entity`. Home Assistant validates a call against them and shows a form in
  the UI; read the values with `findString()`, `findNumber()`, `findBool()` and
  `findEntityId()`.
- A call is answered like a switch command: returning accepts it,
  `CommandException::rejected()` fails it with your message, and a call to a
  paused or stopped app fails at once.
- Actions return no response data.
- The service stays registered while Stewart is away, and calls fail until it
  is back. `remove()`, pruning and unloading the integration remove it.
- An action shares its key space with the app's entities. Two Stewart
  instances cannot hold the same action; the second gets `ConfigInvalid`.

## Changing the configuration

`updateConfig()` replaces an entity's configuration in place: its name, icon,
options, limits and the rest; its device and entity ID stay. `getConfig()`
returns the current one.

```php
$mode->updateConfig(new SelectConfig(['eco', 'comfort'], name: 'Mode'));
```

When the current value no longer fits, for example an option you removed,
Home Assistant shows it as unknown and `getValue()` (`getOption()`) returns
`null`. A configuration Home Assistant refuses throws `ConfigInvalid` and leaves
the previous one in place.

## Restarts and outages

Exposing a key does not set a state, so the handle starts with what Home
Assistant restored: `getValue()` survives a restart of either side. While Home
Assistant is unreachable, `expose*()` still returns a handle, and its entity ID
is `null` until the next connect. Stewart keeps the last state of every exposed
entity and sends them all again after each reconnect.

When an app fails or stops, or its worker dies, its entities stay in Home
Assistant with their last state but turn unavailable. They become available
again when the app exposes them after its restart. Calling `expose*()` makes an
entity available, even if the app marked it unavailable before.

## Pruning

About 30 seconds after every worker is ready, Stewart removes the entities of
this daemon that no running app exposed, with their registry entries and any
device left empty. Entities of apps that failed or did not start stay,
unavailable. Expose entities in `initialize()`: an entity exposed later, for
example from a schedule, is removed at startup and created again, losing what
was set on it in Home Assistant. Set `expose.prune: false` to keep every entity.

Pruning runs only for a daemon that connects. To clean up after one you no
longer run, for example a test `expose.instance`, delete its hub under
**Settings → Devices & services → Stewart**: its devices and entities go with
it. A single device can be deleted the same way. Home Assistant refuses while
the daemon is connected; a daemon that connects again creates its entities again.

## Errors

Every handle call waits for Stewart's main process and can throw `ExposureException`:

| Reason | When |
|---|---|
| `ComponentMissing`, `ProtocolMismatch`, `ComponentRefused` | The integration is not installed, has another version, or refused the session |
| `ConfigInvalid` | Home Assistant rejects the configuration, for example a unit that does not fit the device class |
| `StateInvalid` | Home Assistant rejects the value |
| `KeyInvalid` | The key is not lowercase letters, digits and `_`, starting with a letter |
| `KeyTaken` | The app already exposes that key |
| `Removed` | The handle was removed or its app stopped |
| `Unreachable`, `TimedOut` | Stewart is not connected, or Home Assistant did not answer in time |

`stewart_app_exposed_commands_total{app,outcome}` counts commands per app as
`accepted`, `rejected` (by the app), `refused` (paused or not running) and `lost`
(the worker died while handling it).

## Testing

`$harness->entities` records what the app exposes. `requireSwitch('heater')`, `requireSensor()`, `requireNumber()`,
`requireAction()` and the others return the recorded handle. `pushCommand()` and `pushCall()` run your handlers as
Home Assistant would; a rejection is thrown into the test:

```php file=../../examples/tests/HeaterSwitchTest.php
```

`seedSnapshot()` sets what Home Assistant would have restored, and `refuseExposures()` makes `expose*()` throw.

## Common problems

**`ComponentMissing`.** The Stewart integration is not installed, or Home Assistant was not restarted after installing
it. The app keeps running without its entities.

**`ProtocolMismatch`.** The integration and Stewart have different versions. Update the one that is behind.

**Entities appear twice, or keep turning unavailable.** Two Stewart installations use the same `expose.instance`. Give
each its own.
