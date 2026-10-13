---
title: Areas, floors and labels
description: Pick entities by area, floor, label or device, look up Home Assistant's registries, and edit entity entries.
sidebar:
  order: 11
---

Entity IDs say what a thing is. Home Assistant's areas, floors and labels say where it is and what it is for. An
`EntityFilter` picks entities by them. This app turns on an area's lights when a motion sensor in that area fires:

```php file=../../examples/apps/RoomMotion.php
```

A filter works wherever entities are chosen: `watchStateChanges()` and `listStates()`. Add a new motion sensor to the
kitchen in Home Assistant, label it `motion`, and the app uses it without a change.

## Building a filter

Start with one condition and add more with `with…()`:

| Start | Add | Matches |
|---|---|---|
| `EntityFilter::inArea('kitchen')` | `->withArea(…)` | The entity's area |
| `EntityFilter::onFloor('upstairs')` | `->withFloor(…)` | The floor of that area |
| `EntityFilter::labelled('night')` | `->withLabel(…)` | A label on the entity, its device or its area |
| `EntityFilter::ofDevice($deviceId)` | `->withDevice(…)` | The entity's device |
| `EntityFilter::inDomain('light')` | `->withDomain(…)` | The part of the ID before the dot |
| `EntityFilter::matching('sensor.*_battery')` | `->withSelector(…)` | An ID or pattern |

Several values in one condition mean "any of these"; all conditions must match.
`EntityFilter::inArea('kitchen', 'hall')->withDomain('light')->withLabel('night')` is a light in the kitchen or the
hall, labelled `night`.

Areas, floors, labels and devices are named by their **ID**, not their display name: `kitchen`, not `Kitchen`. Find
an ID in Home Assistant under **Settings → Areas, labels & zones**, or look it up by name (below). After
`make generate`, PhpStorm completes the IDs.

## How Home Assistant places an entity

Stewart follows Home Assistant's rules, so a filter and `ServiceTarget::forAreas('kitchen')` reach the same entities:

- An entity's **area** is its own, or else its device's.
- Its **floor** is the floor of that area.
- Its **labels** are its own, its device's and its area's.
- A **hidden** entity, or a configuration or diagnostic one, never matches an area, floor, label or device.

An entity without an entry in the entity registry, such as a YAML entity without a unique ID, has no area, floor,
labels or device.

## Looking things up

`$this->ha->getRegistry()` returns Stewart's copy of the registries. Lookups are instant.

| Method | Returns |
|---|---|
| `listAreas()`, `findArea($id)`, `findAreaByName('Kitchen')` | Areas; a name also matches an alias, ignoring case |
| `listAreasOnFloor($floorId)` | The areas on one floor |
| `listFloors()`, `findFloor($id)`, `findFloorByName(…)` | Floors |
| `listLabels()`, `findLabel($id)`, `findLabelByName(…)` | Labels |
| `listDevices()`, `findDevice($id)`, `listDevicesInArea($areaId)` | Devices |
| `listEntities()`, `findEntity($entityId)` | Entity registry entries |
| `findEntityPlacement($entityId)` | The device, area, floor and labels an entity ends up with |

To turn a display name into an ID: `$this->ha->getRegistry()->findAreaByName('Kitchen')?->areaId`.

## When the registries change

Stewart reads the registries on every connect, and again a second after Home Assistant reports a change. A running
watch follows the new registries from the next state change. Moving a light into the kitchen does not change its
state, so a kitchen watch notices that light the next time it changes.

## Editing entity entries

An app that asks for `RegistryEditor` can change any entity's entry: name, icon, area, labels, aliases, and whether it
is hidden or disabled. This app hides battery sensors and labels them:

```php file=../../examples/apps/HideBatteries.php
```

- Fields the update leaves out stay as they are. `withoutName()`, `withoutIcon()` and `withoutArea()` hand a field
  back to the integration or device.
- `withLabels()` and `withAliases()` replace the whole list. `withAddedLabels()` and `withRemovedLabels()` (and the
  same for aliases) keep the entries others set.
- `updateEntity()` returns the entry Home Assistant saved. Stewart's copy catches up about a second later.
- Edits count against the service call limits, and with dry run on they are logged, not sent.

It throws `RegistryEditException`:

| Reason | When |
|---|---|
| `NotFound` | The entity has no registry entry |
| `NothingToUpdate` | The update changes nothing |
| `Rejected` | Home Assistant refused it, for example enabling an entity of a disabled device |
| `Unreachable`, `TimedOut`, `Overloaded` | As for [service calls](calling-services.md#when-a-call-fails) |

## Testing

`$harness->ha->registry` is an in-memory registry. Seed areas, floors, labels, devices and entities, and filters
resolve against them:

```php file=../../examples/tests/RoomMotionTest.php
```

Edits through `$harness->registryEditor` change the same in-memory registry, and its `updates` property lists them:

```php file=../../examples/tests/HideBatteriesTest.php
```

## Common problems

**The filter matches nothing.** It needs IDs, not display names. Check the entity's area under **Settings → Devices &
services → Entities**, and whether the entity is hidden.

**Floors and labels are empty.** Home Assistant before 2024.4 has none.
