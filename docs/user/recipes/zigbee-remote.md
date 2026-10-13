---
title: Zigbee remote over MQTT
description: Control a light from a Zigbee2MQTT remote by reading its MQTT messages directly.
sidebar:
  order: 7
---

Reads the button presses of a Zigbee remote straight from Zigbee2MQTT, and turns a light on, off, brighter or dimmer.
No Home Assistant automation is involved, and presses are handled as soon as they arrive.

**You need** Zigbee2MQTT and an MQTT server that Stewart is [connected to](../guides/mqtt.md).

```php file=../../examples/apps/ZigbeeRemote.php
```

```yaml
apps:
  zigbee-remote:
    options:
      remote: living_room_remote   # the remote's friendly name in Zigbee2MQTT
      light: light.living_room
```

## How it works

- Zigbee2MQTT publishes each press on `zigbee2mqtt/<name>/action`, with the action as the payload.
- A `match` turns each action into a service and its data. Unknown actions are ignored.
- `brightness_step_pct` changes the brightness relative to where it is.

## Test

```php file=../../examples/tests/ZigbeeRemoteTest.php
```

## Make it yours

- Find your remote's actions: watch `zigbee2mqtt/<name>/action` with `mosquitto_sub`, and press each button.
- Long presses: many remotes send `brightness_move_up` and `brightness_stop`; start a `runEvery()` on the first and
  cancel it on the second.
