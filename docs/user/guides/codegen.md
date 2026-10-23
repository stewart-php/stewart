---
title: Typed entities
description: Generate PHP classes from your own Home Assistant, so your editor completes entity IDs, attributes and service fields.
sidebar:
  order: 9
---

`make generate` reads your Home Assistant once and writes a PHP class for every domain, entity and service into
`generated/`. Your editor then completes entity IDs, attributes and service fields, and PHPStan checks them:

```php file=../../examples/apps/TypedHallLight.php
```

- `Entities` has one property per domain: `$this->entities->light`, `$this->entities->binarySensor`.
- `getEntity('light.hall')` returns a typed handle. Its methods are that domain's services: `turnOn()` takes the
  light's real fields, such as `brightnessPct` and `colorTempKelvin`.
- `Services` has one property per domain, with every service of it: `$this->services->notify->mobileAppPhone()`.
- `LightState::fromNullableState($change->to)` turns a state into a typed one, with `isOn()` and an accessor per
  attribute, such as `getBrightness()`.

Both classes are ordinary constructor parameters, and calls through them appear in the log under the app's ID, like
calls through `HaContext`.

## Generating

```bash
make generate
```

Run it again after you add devices, and commit `generated/`. A build or a server then never needs Home Assistant to
create the classes.

If Home Assistant has entities the classes do not, or the classes have entities Home Assistant no longer has, Stewart
logs a warning:

```
… WARNING [broker] Generated entity classes no longer match Home Assistant; run stewart generate {"added":["light.landing"],"removed":[]}
```

It checks at start, after each reconnect to Home Assistant, after entities are added or removed in Home Assistant, and
after each [reload](status.md#reloading), so `make generate` followed by `make reload` shows the new classes match. The
warning comes again only when the list changes, and one line says when the classes match again. `make status` shows
the same on its `generated` row (`up to date`, `2 added, 1 removed; run stewart generate`, or a format from an older
Stewart), and so do the metrics `stewart_generated_entities_drift{change}` and `stewart_generated_format_current`.

`make generate ARGS=--check` reports what would change without writing anything, and fails if something would. When
more than half of the files would be deleted, `make generate` stops, in case it reached the wrong Home Assistant;
`ARGS=--allow-shrink` lets it go ahead.

## Entity IDs from options

`getEntity()` takes a string, so an ID from the app's options works like one written in the code:

```php file=../../examples/apps/LightsOut.php
```

```yaml
apps:
  lights-out:
    options:
      lights: [light.hall, light.porch]
```

`getEntity()` accepts only IDs of its domain that the last `make generate` saw. Any other throws `IdentifierException`
with the reason `EntityNotGenerated`. Until you generate again, a new entity is still reachable through
`$this->ha->getEntity('light.new')`.

Because IDs are written in full, searching your project for `light.hall` finds every place that uses it.

## What gets generated

| Class | Holds |
|---|---|
| `Entities` | One property per domain with entities |
| `LightEntities` | `getEntity('light.…')` for the domain |
| `LightEntity` | One entity: `getState()`, `requireState()`, `watchStateChanges()`, `waitForState()`, `getHistory()`, and the domain's services as methods |
| `LightState` | A typed state: `isOn()`, `isOff()`, `isUnavailable()`, `getFriendlyName()`, one accessor per attribute, and `raw` for the plain `EntityState` |
| `Services` | One property per domain with services |
| `LightServices` | Every service of the domain |
| `Manifest` | What Home Assistant looked like: its entity IDs and action names, so Stewart can notice changes |
| `.phpstorm.meta.php` | Completion in PhpStorm: entity IDs wherever a method takes one (`getState()`, `watchStateChanges()`, `ServiceTarget::forEntities()`, `runAtEntityTime()`, …), action names for `callAction()`, and area, floor and label IDs |

A few naming rules:

- Domains become camelCase properties: `binary_sensor` is `$entities->binarySensor`.
- A service field with a fixed list of values accepts only those, so PHPStan rejects a value Home Assistant would.
- A service named like an existing method gets a `Service` suffix, and an attribute named like an existing accessor
  gets an `Attribute` suffix.

## Choosing what to generate

Everything is optional; these are the defaults:

```yaml
codegen:
  namespace: App\Generated
  output_dir: generated
  include: ['*']
  exclude: []
```

`include` and `exclude` take entity IDs and patterns, such as `light.debug_*`. Exclude wins.

An attribute gets an accessor when Home Assistant defines it for the domain (such as `brightness` on a light), or
when you include it:

```yaml
codegen:
  attributes:
    sensor:
      exclude: ['moon_*']
    vacuum:
      include: [mop_pad]
```

Accessors are nullable, because Home Assistant drops an attribute when it stops applying. Numbers are always `?float`,
since `21` today can be `21.5` tomorrow. An attribute that was `null` on every entity when you generated (every light
off, for example) is `mixed` until you generate again.

## Snapshots for CI

`--snapshot-out` saves what `generate` read, and `--snapshot-in` generates from that file without connecting. A
snapshot is a copy of your home's state, with locations, IP addresses and access tokens of cameras, so a new
project's CI leaves this check out. In a private repository, you can commit one and check the committed classes
against it:

```bash
make generate ARGS=--snapshot-out=snapshot.json                     # on your machine; commit the file
make generate ARGS="--check --snapshot-in=snapshot.json"           # in CI
```

Generating from the same snapshot always gives the same files. Keep the snapshot out of public repositories; the same
goes for `generated/`, which names your entities.

## Typos caught by PHPStan

`stewart-php/phpstan` checks the entity IDs and action names written in your code against what the last
`make generate` saw, so `make stan` stops a typo before it reaches Home Assistant:

```
 ------ ---------------------------------------------------------------------------------------------
  Line   apps/PorchLight.php
 ------ ---------------------------------------------------------------------------------------------
  24     Entity ID "light.porhc" was not in Home Assistant at the last stewart generate. Did you mean
         "light.porch"?
         🪪  stewart.entityId
 ------ ---------------------------------------------------------------------------------------------
```

- It checks every method that takes an entity ID, such as `getState()`, `watchStateChanges()`,
  `ServiceTarget::forEntities()`, `runAtEntityTime()` and `new EntityId(…)`, and the action names of `callAction()`,
  including `$ha->getEntity('light.hall')->callAction('turn_on')`.
- Only IDs written as literals are checked. Globs such as `light.*`, times such as `07:30`, and IDs from options are
  left alone, and so is code in your tests, which seed made-up entities.
- An ID left out of the generated classes (excluded, disabled or hidden) is fine for `$ha->getState()`, but
  `$this->entities->light->getEntity()` throws for it, so PHPStan reports that call.
- Before the first `make generate` there is nothing to check against, so nothing is reported.

Projects from the skeleton have it. Another project adds it, together with the installer that loads PHPStan
extensions, from `make sh`:

```bash
composer config allow-plugins.phpstan/extension-installer true
composer require --dev stewart-php/phpstan phpstan/extension-installer
```

With a `codegen.namespace` other than `App\Generated`, tell it where the manifest is in `phpstan.neon.dist`:

```neon
parameters:
    stewart:
        manifest: Home\Generated\Manifest
```

An entity that exists only at run time, such as one an app creates, can be silenced with PHPStan's
`ignoreErrors` and the identifier `stewart.entityId` (`stewart.actionName` for actions).

## In production

Projects from the skeleton require `stewart-php/codegen` like any other package, so `stewart generate` also exists on a
server. That is what `STEWART_BOOT_GENERATE=1` (or the Helm chart's `code.generateOnStart`) uses to regenerate the
classes on every start. The generated classes themselves need only `stewart-php/contracts`; a project that always
commits them can move `stewart-php/codegen` to `require-dev`.

## Common problems

**`Entity ID "…" is not a generated … entity`.** The ID has a typo, belongs to another domain, or was added after the last
`make generate`.

**The first `make generate` stops and prints a `composer.json` snippet.** Composer does not know where the classes
go. Add the snippet under `autoload`, run `make install`, then `make generate` again. Projects from the skeleton
already have it.

**`Class "Stewart\Contracts\App\Generated\…" not found`.** An app imports `Stewart\Contracts\App`, so inside the app,
`App\Generated\Entities` means `Stewart\Contracts\App\Generated\Entities`. Import the generated classes with
`use App\Generated\Entities;` instead.
