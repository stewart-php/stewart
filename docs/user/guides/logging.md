---
title: Logs
description: Read a Stewart log line, send the log to a collector as JSON, and let one app say more or less than the rest.
sidebar:
  order: 17
---

Stewart writes its log to standard output: `make logs` follows it, and `make run` shows it in the foreground. A
line looks like this:

```text
2026-10-21 14:03:12.345+02:00 INFO [porch-light] Turning on {"entity":"light.porch","worker":0}
```

| Part | What it says |
|---|---|
| `2026-10-21 14:03:12.345+02:00` | When, with the offset of Stewart's time zone |
| `INFO` | The level: `DEBUG`, `INFO`, `NOTICE`, `WARNING`, `ERROR` or worse |
| `[porch-light]` | What the line is about (see below) |
| `Turning on` | The message |
| `{…}` | The details; `worker` is the worker the app runs on |

The tag in brackets is one of:

| Tag | Lines |
|---|---|
| An app ID, such as `[porch-light]` | What the app logs through its `LoggerInterface`, and what Stewart logs about it, such as `App failed` or `App paused` |
| `[@shared]` | Stewart's own code inside a worker, outside any app |
| `[broker]` | The daemon itself: the connection to Home Assistant, workers, reloads |
| `[worker 0]` | Text a worker printed outside the logger, such as a PHP fatal error or a `var_dump()` |

So `make logs | grep '\[porch-light\]'` shows everything about one app.

## JSON

For a log collector such as Loki or Elasticsearch, `log_format: json` (or `STEWART_LOG_FORMAT=json`, or
`stewart run --json-logs`) writes one JSON object per line. `extra.source` holds the tag, and `context.app` and
`context.worker` name the app and its worker:

```json
{"message":"Turning on","context":{"entity":"light.porch","app":"porch-light","worker":0},"level":200,"level_name":"INFO","channel":"stewart","datetime":"2026-10-21T14:03:12.345678+02:00","extra":{"source":"porch-light"}}
```

## One app's log level

`log_level` sets how much Stewart says: `debug`, `info` (the default), `notice`, `warning` or `error`. One app can
say more or less than that:

```yaml
log_level: info
apps:
  porch-light:
    log_level: debug     # its debug lines too
  energy-report:
    log_level: warning   # only its warnings and errors
```

From the environment, that is `STEWART_APPS__PORCH_LIGHT__LOG_LEVEL=debug`.

An app's level covers what the app logs and what Stewart logs about it; `[broker]` and `[@shared]` lines keep the
global `log_level`. A [reload](status.md#reloading) (`make reload`) applies a changed app level. The global
`log_level`, like `--log-level`, takes effect at the next restart.

## Tracing an app

When an app does not react, or reacts when it should not, trace it. A traced app logs what reaches it and what it does
with it, labelled `TRACE`, whatever `log_level` says:

```bash
make run TRACE=porch-light     # also make watch and make debug; TRACE="porch-light hall" for two
```

`stewart run --trace=porch-light` does the same; repeat it for more apps. To keep an app traced, set it in
`stewart.yaml`, and a reload applies it:

```yaml
apps:
  porch-light:
    trace: true
```

A trace reads like this:

```text
… TRACE [porch-light] Event received {"subscription":"state_change exact:binary_sensor.porch_motion","id":"w0:3","change":"on → off"}
… TRACE [porch-light] Event received {"subscription":"state_change exact:binary_sensor.porch_motion","id":"w0:3","change":"off → on"}
… TRACE [porch-light] Handler called {"subscription":"state_change exact:binary_sensor.porch_motion","id":"w0:3"}
… TRACE [porch-light] Service call sent {"service":"light.turn_on","target":{"entity_id":["light.porch"]}}
… TRACE [porch-light] Service call answered {"service":"light.turn_on","outcome":"succeeded","took":"42ms"}
```

| Line | Means |
|---|---|
| `Event received` | A state change, event, topic, MQTT message or trigger reached one of the app's subscriptions, before its operators |
| `Handler called` | The event got through the operators (`filter()`, `whenChangedTo()`, `debounce()`, …) and the app's handler ran |
| `Event skipped while paused` | The app is paused, so the event went nowhere |
| `Schedule ran`, `Schedule run skipped while paused` | A schedule or a timer came due |
| `Service call sent`, `Service call answered` | A call to Home Assistant, how it went and how long it took |

An `Event received` with no `Handler called` after it means an operator stopped the event: a `filter()` said no, or
`whenChangedTo('on')` saw a change from `unavailable`. A `debounce()` or `whenStableFor()` calls the handler later, so
its `Handler called` comes on its own. No `Event received` at all means nothing matched: check the entity ID, and that
the app runs (`make status`). `id` tells two subscriptions to the same entity apart.

In JSON, a trace record keeps the debug `level` (100) and has `level_name` `TRACE`. Trace one app rather than
setting `log_level: debug`: debug adds Stewart's own lines for every app.
