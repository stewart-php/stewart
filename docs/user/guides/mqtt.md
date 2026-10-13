---
title: MQTT
description: Publish to and watch topics on an MQTT server from an automation.
sidebar:
  order: 9
---

Apps exchange messages with outside systems over MQTT 3.1.1. The broker keeps
one connection to the server; apps take `Mqtt` in their constructor.

```bash
composer require stewart-php/mqtt
```

```yaml
mqtt:
  url: mqtt://user:password@mosquitto:1883   # mqtts:// for TLS
  will: { topic: stewart/status, payload: offline, qos: 1, retain: true }
```

```php file=../../examples/apps/ThermostatBridge.php
```

- Filters use MQTT syntax: `+` is one level, `#` is the rest. A filter starting
  with a wildcard does not match `$SYS/...`.
- `publish()` takes a string, or an array that is sent as JSON.
  `MqttMessage::decodeJsonPayload()` reads one back.
- QoS 0 and 1 are supported. A QoS 1 message published while the server is
  unreachable waits in `mqtt.outbound_buffer` and is sent after the reconnect.
- Without `mqtt.url`, an app that asks for `Mqtt` is not started.

In unit tests, `Stewart\Testing\Mqtt\RecordingMqtt` stands in for the real thing:
`pushMessage()` feeds watchers and `published` lists what the app sent. [`AppHarness`](testing.md)
gives an app one as `$harness->mqtt`.
