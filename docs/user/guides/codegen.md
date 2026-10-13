---
title: Typed entities and services
description: Generate PHP classes over your own Home Assistant, so an entity ID is written once and checked forever.
sidebar:
  order: 10
---

`stewart generate` connects to your instance once and writes a class per domain,
per entity and per service. After that an automation reads like this:

```php
public function __construct(
    private readonly Entities $entities,
    private readonly Services $services,
) {}

public function initialize(): void
{
    $this->entities->binarySensor->getEntity('binary_sensor.hall_motion')->watchStateChanges()
        ->whenChangedTo('on', from: 'off')
        ->subscribe(fn() => $this->entities->light->getEntity('light.hall')->turnOn(brightnessPct: 70));

    $this->services->notify->mobileAppPhone(message: 'Hall light on');
}
```

Both roots are ordinary constructor arguments. They are bound to the asking
automation's own context, so a call through them carries its ID into the logs
exactly as `HaContext` does.

## Looking up an entity

An entity is reached by its ID, written in full, so searching the project for
`light.hall` finds every place it is used. `getEntity()` takes a string or an
`EntityId`, which makes an ID from the app's `options` as good as a literal:

```yaml
apps:
  porch:
    options:
      lights: [light.porch, light.porch_2]
```

```php
/** @param list<string> $lights */
public function __construct(
    private readonly Entities $entities,
    private readonly array $lights,
) {}

public function initialize(): void
{
    foreach ($this->lights as $id) {
        $this->entities->light->getEntity($id)->turnOff();
    }
}
```

`getEntity()` accepts only the IDs of its own domain that the last
`stewart generate` saw; any other throws `IdentifierError::EntityNotGenerated`.
Inside a handler that is reported like any handler failure and the app keeps
running; inside `initialize()` it fails that app alone. An entity added since
the last generate is reachable untyped through `HaContext::getEntity()` until
you regenerate.

The generator also writes `.phpstorm.meta.php` next to the classes, so PhpStorm
completes the IDs inside `getEntity('…')`, and area, floor and label IDs inside
`EntityFilter`, the `Registry` lookups and `ServiceTarget::forAreas()` and its
siblings.

## Running it

```
make generate          write the classes
make generate-check    fail if they no longer match Home Assistant
```

The first run needs Composer to know where the classes go. If it does not, the
command prints the snippet to paste and stops without writing anything.

```json
"autoload": {
    "psr-4": {
        "App\\Generated\\": "generated/"
    }
}
```

Then `make install`, and `make generate` again.

## Configuration

Everything is optional; these are the defaults.

```yaml
codegen:
  # Namespace of the generated classes. Composer must map it to output_dir.
  namespace: App\Generated

  # Written relative to the project root.
  output_dir: generated

  # Entity selectors: exact IDs or globs. Exclude wins over include.
  include:
    - '*'
  exclude:
    - light.debug_*
```

An excluded entity is *remembered* rather than forgotten: it goes into the
manifest's ignored list, so the daemon does not report it as having appeared.

## What you get

| Class | What it is |
|---|---|
| `Entities` | One property per domain that has entities: `$entities->light` |
| `LightEntities` | `getEntity('light.hall')`, for the domain's generated IDs |
| `LightEntity` | One entity, a `TypedEntity`: `id`, `getState()`, `requireState()`, `watchStateChanges()`, `listAttributes()`, `getEntity()`, and the domain's own entity-targeted services as methods |
| `LightState` | That domain's state: `value`, `raw`, `isOn()`, `isOff()`, `getFriendlyName()`, `isUnavailable()`, and a typed accessor per attribute |
| `Services` | One property per domain that has services: `$services->notify` |
| `LightServices` | Every service of the domain, taking `ServiceTarget|Entity` first where the service is targetable |
| `Manifest` | What the instance looked like, which is how the daemon notices drift |

A state change hands you a `StateChange`, not a typed view. Wrap it where you
want one:

```php
$this->entities->light->getEntity('light.hall')->watchStateChanges()->subscribe(function (StateChange $change): void {
    $to = LightState::fromNullableState($change->to);

    if ($to?->isOn() === true) {
        $this->log->info('on at', ['brightness' => $to->getBrightness()]);
    }
});
```

`requireState()` throws `StateError::EntityNotFound` where `getState()` returns `null`, so use
it when the entity not existing is a bug rather than a state.

A service field with a fixed list of options is documented as a literal union,
so PHPStan rejects a value Home Assistant would:
`@param 'long'|'short'|null $flash`. A select that accepts custom values stays
`string`.

## Naming

Entity IDs are never turned into names; only domains, services and attributes
are. Domains become camelCase properties: `binary_sensor` is
`$entities->binarySensor`. Two rules cover the rest.

- A service whose name is already a member of the handle gains a `Service`
  suffix: a service called `get_state` is `$hall->getStateService()`, and stays
  `getState()` on `$services->light`.
- An attribute whose accessor is already a member of the state view gains an
  `Attribute` suffix: an attribute called `state_as_float` reads as
  `getStateAsFloatAttribute()`.

Class names carry their own suffix — `LightEntities`, `LightEntity`,
`LightState`, `LightServices` — so a domain named after a PHP keyword, like
`switch`, is safe.

## Attribute types

A domain's state view gets a typed accessor for an attribute key only when
Home Assistant defines the key for the platform, such as `brightness` on a
light or `unit_of_measurement` anywhere, or when you include it. Everything
else is still there through `listAttributes()` and `raw`.

- A key matching `exclude` never gets one, known or included.
- A key matching `include` gets one when some entity reports it.
- `friendly_name` and keys that do not start with a letter, such as
  `06-19 13:01`, never get one.
- Two keys that reduce to the same name are numbered: `localTime`,
  `localTime2`.

```yaml
codegen:
  attributes:
    sensor:
      exclude: ['moon_*']
    vacuum:
      include: [mop_pad, 'mop_*']
```

Patterns are entity-selector patterns: an exact key, or a glob with `*` and `?`. An exact include that no entity of its domain
reports is printed as a warning, since it is most likely a typo.

The keys that remain are widened to a type every entity can answer with:

| Seen | Accessor |
|---|---|
| any number | `?float` |
| anything in conflict | `mixed` |
| an array | `array<array-key, mixed>\|null` |

Whole numbers get `?float` too: an attribute that reads `21` today can read `21.5` tomorrow.

Every accessor is nullable, because Home Assistant drops an attribute the moment
it stops applying. `unavailable` and `unknown` entities contribute nothing.
`isOn()` / `isOff()` and `getStateAsFloat()` come from a fixed list of domains rather
than from the snapshot, so a house whose lights are all off still gets `isOn()`.

The flip side of reading types off live state: an attribute that was `null` on
every entity of its domain — every light off, say — is generated as `mixed`, and
becomes typed the next time one of them is on.

## Snapshots, and CI

```
stewart generate --snapshot-out=var/snapshot.json    write down what was read
stewart generate --snapshot-in=var/snapshot.json     generate from it, no connection
stewart generate --check                             report what would change, exit 1
```

Rendering from a given snapshot is byte-for-byte deterministic: everything is
sorted and nothing carries a timestamp. Rendering from a *live* instance is not
quite, for the attribute reason above, so `--check` in CI belongs against a
committed snapshot:

```
stewart generate --check --snapshot-in=var/snapshot.json
```

A snapshot is a copy of your instance's live state: every attribute value,
including camera and image `access_token`s, tracker and zone coordinates, IP and
MAC addresses, and the user ids in each state's context. It also holds the
entity registry and your areas, floors and labels. Keep it out of public
repositories.

The config still needs `home_assistant.url` and `token` even with
`--snapshot-in`; nothing connects, but the schema is one schema.

## Drift

After its first snapshot the daemon compares Home Assistant's entity list with
the manifest and logs one warning if they have parted ways:

```
WARNING Generated entity classes no longer match Home Assistant; run stewart generate
        {"added":["light.landing"],"removed":[]}
```

Services are not checked at boot — the daemon never calls `get_services` —
so `stewart generate --check` is what catches a service that moved.

## In production

`stewart-php/codegen` is a development dependency. A `--no-dev` image has
neither the package nor the `generate` command, and the daemon does not notice.
The generated classes themselves depend on `contracts` alone, so they cost a
production install nothing beyond their own bytes.
