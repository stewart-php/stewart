---
title: Areas, floors and labels
description: Pick entities by where they are and how they are labelled, using Home Assistant's own registries.
sidebar:
  order: 16
---

Entity IDs say what a thing is; Home Assistant's registries say where it is.
`EntityFilter` picks entities by area, floor, label or device, and works
wherever a selector does in `listStates()` and `watchStateChanges()`:

```php
use Stewart\Contracts\Registry\EntityFilter;

$this->ha->watchStateChanges(EntityFilter::inArea('kitchen')->withDomain('light'))
    ->whenChangedTo('on')
    ->subscribe(fn(StateChange $change) => $this->logger->info('Kitchen light on', ['light' => (string) $change->entityId]));

$nightLights = $this->ha->listStates(EntityFilter::labelled('night')->withDomain('light'));
```

## Building a filter

A filter starts with one condition and grows with `with*()`. Each call returns a
new filter.

| Start | Extend | Matches |
|---|---|---|
| `EntityFilter::inArea('kitchen', ...)` | `->withArea(...)` | the entity's area |
| `EntityFilter::onFloor('upstairs', ...)` | `->withFloor(...)` | the floor of that area |
| `EntityFilter::labelled('night', ...)` | `->withLabel(...)` | a label on the entity, its device or its area |
| `EntityFilter::ofDevice($deviceId, ...)` | `->withDevice(...)` | the entity's device |
| `EntityFilter::inDomain('light', ...)` | `->withDomain(...)` | the domain part of the ID |
| `EntityFilter::matching('sensor.*_battery')` | `->withSelector(...)` | an ID selector, as in `listStates()` |

Values within one condition are alternatives; all conditions must match.
`EntityFilter::inArea('kitchen', 'hall')->withDomain('light')->withLabel('night')`
is a light, in the kitchen or the hall, labelled `night`.

Areas, floors, labels and devices are named by their **ID**, not their display
name: `kitchen`, not `Kitchen`. Each takes a string or a typed `AreaId`,
`FloorId`, `LabelId` or `DeviceId`. After `stewart generate`, PhpStorm completes
area, floor and label IDs.

## How an entity is placed

The rules are Home Assistant's own, so a filter and a
`ServiceTarget::forAreas('kitchen')` reach the same entities:

- An entity's **area** is its own, or else its device's.
- Its **floor** is the floor of that area.
- Its **labels** are its own, its device's and its area's.
- A **hidden** entity, or a config or diagnostic one, is never matched by area,
  floor, label or device. Domain and ID conditions still match it.

An entity that is not in the entity registry, such as a YAML entity without a
unique ID, has no area, floor, labels or device.

## Looking things up

`HaContext::getRegistry()` returns the registry as Home Assistant last reported
it. Every lookup is local and never suspends.

| | |
|---|---|
| `listAreas()`, `findArea($id)`, `findAreaByName('Kitchen')` | areas; a name also matches an alias, ignoring case |
| `listAreasOnFloor($floorId)` | the areas on one floor |
| `listFloors()`, `findFloor($id)`, `findFloorByName(...)` | floors |
| `listLabels()`, `findLabel($id)`, `findLabelByName(...)` | labels |
| `listDevices()`, `findDevice($id)`, `listDevicesInArea($areaId)` | devices; `getDisplayName()` prefers the name the user gave |
| `listEntities()`, `findEntity($entityId)` | entity registry entries |
| `findEntityPlacement($entityId)` | the resolved device, area, floor and labels of one entity |

A display name turns into the ID a filter needs with
`$this->ha->getRegistry()->findAreaByName('Kitchen')?->areaId`.

## When the registry changes

The broker reads the area, floor, label, device and entity registries on every
connect. When Home Assistant reports a `*_registry_updated` event, it waits for
a second of quiet and reads them again, then every worker gets the new copy.

A running watch keeps its filter and follows the new registry from the next
state change. Moving a light into the kitchen does not by itself produce a state
change, so a kitchen watcher hears that light the next time it changes.

On Home Assistant older than 2024.4 there are no floors or labels; those lists
are empty.

## Editing entries

An app that injects `RegistryEditor` changes an entity's registry entry: its
name, icon, area, labels and aliases, and whether it is hidden or disabled. It
works on any entity, not only Stewart's own:

```php
use Stewart\Contracts\Registry\RegistryEditor;
use Stewart\Contracts\Registry\Update\EntityRegistryUpdate;

public function __construct(private readonly RegistryEditor $registry) {}

$entity = $this->registry->updateEntity('sensor.hall_battery', new EntityRegistryUpdate()
    ->withName('Hall battery')
    ->withArea('hall')
    ->withAddedLabels('battery')
    ->withHidden(true));
```

A field the update leaves out stays as it is; `withoutName()`, `withoutIcon()`
and `withoutArea()` hand it back to the integration or the device.
`withLabels()` and `withAliases()` replace the whole list, while
`withAdded*()` and `withRemoved*()` merge with the entry Home Assistant holds at
the moment of the edit, so labels another app or a user set stay.
`EntityAlias::entityName()` is the alias that stands for the entity's own name.

`updateEntity()` returns the entry Home Assistant stored. The mirrored registry
catches up a second later through the usual refresh. The mirror never carries
aliases, so `RegisteredEntity::$aliases` is `null` there.

Edits share the service-call budget and follow `service_calls.dry_run`, which
answers with the entry as it would be. Editing an entity a Stewart app exposes
logs a warning: its name and icon belong to the handle's `updateConfig()`.

| Reason | When |
|---|---|
| `NotFound` | the entity is not in the entity registry |
| `NothingToUpdate` | the update changes nothing |
| `Rejected` | Home Assistant refused it, e.g. enabling an entity of a disabled device |
| `Unreachable`, `TimedOut`, `Overloaded` | as for service calls |

## Testing

`RecordingHaContext::$registry` is an `InMemoryRegistry`. Seed it, and filters
in `listStates()` and `watchStateChanges()` resolve against it:

```php
$ha = new RecordingHaContext();
$ha->registry
    ->seedArea(new Area(new AreaId('kitchen'), 'Kitchen'))
    ->seedEntity(new RegisteredEntity(new EntityId('light.kitchen'), areaId: new AreaId('kitchen')));

$ha->pushState('light.kitchen', 'on'); // reaches an EntityFilter::inArea('kitchen') watcher
```

`seedFloor()`, `seedLabel()` and `seedDevice()` complete the picture; a device's
area and labels pass to its entities as they do in Home Assistant.

`RecordingRegistryEditor` records each update in `$updates` and applies it to
the `InMemoryRegistry` it was given, so the app's next read sees the change:

```php
$ha = new RecordingHaContext();
$ha->registry->seedEntity(new RegisteredEntity(new EntityId('sensor.hall_battery')));
$editor = new RecordingRegistryEditor($ha->registry);

new HideBatteries($ha, $editor)->initialize();

self::assertTrue($ha->registry->findEntity('sensor.hall_battery')?->isHidden());
self::assertCount(1, $editor->updates->filterByEntityId('sensor.hall_battery'));
```

[`AppHarness`](testing.md) wires the two together as `$harness->ha` and `$harness->registryEditor`.
