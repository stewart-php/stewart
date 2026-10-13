---
title: MQTT
description: Publish to and receive messages from an MQTT server, such as Zigbee2MQTT or other devices on your network.
sidebar:
  order: 14
---

Apps can exchange messages with an MQTT server, for example to read Zigbee2MQTT devices directly or to talk to
devices Home Assistant does not know. Stewart keeps one connection to the server, and apps ask for `Mqtt` in their
constructor. This app copies temperatures from MQTT into Home Assistant, and publishes the living room thermostat's
state back:

```php file=../../examples/apps/ThermostatBridge.php
```

## Setting it up

Set the server in `.env`:

```bash
STEWART_MQTT__URL=mqtt://user:password@mosquitto:1883
```

Use `mqtts://…:8883` for TLS. Without a URL, an app that asks for `Mqtt` does not start. Projects from the skeleton
already have `stewart-php/mqtt` installed; other projects run `composer require stewart-php/mqtt`.

Optional settings in `stewart.yaml`:

```yaml
mqtt:
  client_id: stewart-home        # default: stewart-<hostname>
  keepalive: 30s
  will: { topic: stewart/status, payload: offline, qos: 1, retain: true }
```

The `will` is a message the server publishes for Stewart when its connection dies.

## Receiving

`watchMessages('zigbee2mqtt/+/temperature')` returns a stream of `MqttMessage`s with `topic`, `payload` (text),
`qos` and `retain`. `decodeJsonPayload()` reads a JSON payload.

Topic filters use MQTT's wildcards: `+` matches one level, `#` the rest. A filter that starts with a wildcard does not
match `$SYS/…` topics.

## Publishing

`publish($topic, $payload, $qos, retain: …)` sends a message. A string payload is sent as it is, and an array as JSON.

QoS 0 and 1 are supported. A QoS 1 message published while the server is unreachable waits, up to
`mqtt.outbound_buffer` messages, and is sent after the reconnect.

## Testing

`$harness->mqtt` stands in for the server. `pushMessage()` sends a message to the app, and `published` lists what the
app sent:

```php file=../../examples/tests/ThermostatBridgeTest.php
```

## Common problems

**`MQTT is not configured`.** Set `STEWART_MQTT__URL` in `.env`.

**Nothing arrives.** Check the topic with an MQTT client, such as `mosquitto_sub -h host -t '#' -v`. Topics are
case-sensitive.
