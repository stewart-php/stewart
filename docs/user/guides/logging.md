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
