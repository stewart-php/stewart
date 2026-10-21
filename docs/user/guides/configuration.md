---
title: Configuration
description: Set Stewart's settings in stewart.yaml or environment variables, keep secrets in files, and see what is in effect.
sidebar:
  order: 7
---

Stewart reads its settings from three places. A later one wins over an earlier one:

1. **`stewart.yaml`** in your project. It belongs in Git, so keep secrets out of it.
2. **Environment variables**, usually from `.env`. Secrets go here.
3. **Command-line options** of `stewart run`: `--config`, `--log-level` and `--json-logs`.

Every setting has a default, so you only write down what differs. The [configuration reference](../reference/configuration.md)
lists them all, and two commands show them too:

```bash
make config-reference   # every setting, with its default and a short explanation
make config             # the settings in effect right now, secrets masked
```

## stewart.yaml

```yaml
log_level: info
workers: 2

home_assistant:
  command_timeout: 45s

apps:
  porch-light:
    options:
      light: light.front_porch
```

`stewart.yaml` is optional. Without it, the environment alone configures Stewart.

## Environment variables

Every setting can also be an environment variable. Write `STEWART_`, then the keys in capitals, joined by **two**
underscores. A single underscore belongs to a key's name.

| In stewart.yaml | As an environment variable |
|---|---|
| `log_level` | `STEWART_LOG_LEVEL` |
| `home_assistant.token` | `STEWART_HOME_ASSISTANT__TOKEN` |
| `apps.porch-light.options.offAt` | `STEWART_APPS__PORCH_LIGHT__OPTIONS__OFF_AT` |

- An app option written in camelCase (`offAt`) becomes `OFF_AT`.
- An app ID's `-` becomes `_`, so `porch-light` is `PORCH_LIGHT`; the app needs no entry in `stewart.yaml`.
- A variable that starts with `STEWART_` but names no setting stops Stewart with an error, so a typo does not go
  unnoticed.
- An empty variable counts as not set, so the default applies.

**Lists and maps** take inline YAML, and replace the whole value:

```bash
STEWART_APPS__PORCH_LIGHT__OPTIONS__LIGHTS='[light.porch, light.garden]'
STEWART_CODEGEN__INCLUDE='[light.*, switch.*]'
```

An environment variable always replaces an option's whole value; it cannot change one key inside a list or map.

## Secrets in files

Add `_FILE` to a variable's name to read the value from a file. This fits Docker and Kubernetes secrets:

```bash
STEWART_HOME_ASSISTANT__TOKEN_FILE=/run/secrets/home-assistant-token
STEWART_APPS__WEATHER__OPTIONS__API_KEY_FILE=/run/secrets/weather
```

The second line fills the `apiKey` option of the `weather` app.

## Variables inside stewart.yaml

A text value in `stewart.yaml` can include an environment variable, with a fallback after `:-`:

```yaml
home_assistant:
  url: ws://${HA_HOST}:8123/api/websocket
log_level: ${LOG_LEVEL:-info}
```

A variable that is not set and has no fallback stops Stewart with the setting's name. Write `$${` for a literal
`${`.

## Values

- **Durations** are a number and a unit: `500ms`, `5s`, `2m`, `1h`. A bare number is refused, because its unit
  would be a guess. Delays and windows are at least `1ms`; `make config-reference` lists the limits of
  each setting.
- **`off`** switches off a timer, a listener, the control socket, `persistence.url` or `mqtt.url`, for example
  `supervision.ping_interval: off`. `make config-reference` says which settings accept it.
- **App options** from the environment take inline YAML. A value that is not valid inline YAML stays text, so a
  template or a regular expression passes through.
- **A whole-value variable** in `stewart.yaml` is typed like its setting: `workers: ${POOL:-2}` is a number.

## Checking a configuration

`stewart check` loads the configuration and every file in `apps/`, then builds every enabled app the way its worker
does, without running any of your code. It fails, naming each app, on a missing or unknown option, a constructor
argument nothing provides, a `Store` without `persistence.url`, an `Mqtt` without `mqtt.url`, or a `#[PeerStore]`
naming no app. Otherwise it lists every app with its class, the worker it runs on and whether it is enabled, paused
or disabled. Run it in CI or before a deploy: `make check`. The [Git deploy mode](../deploy/git.md) runs it on every
new commit.

A running Stewart applies changes to `apps` and `workers` on a [reload](status.md#reloading) (`make reload`); every
other setting, and anything in `.env`, needs a restart.

## Settings you are likely to change

| Setting | Default | What it does |
|---|---|---|
| `log_level` | `info` | How much Stewart logs: `debug`, `info`, `notice`, `warning` or `error` |
| `log_format` | `line` | `json` for a log collector |
| `workers` | `0` | Worker processes; `0` means one per CPU core, at most 4 |
| `home_assistant.url`, `.token` | none | Where Home Assistant is, and the token |
| `persistence.url` | none | The storage server, for example `redis://valkey:6379/0`. See [Persistence](persistence.md) |
| `mqtt.url` | none | The MQTT server. See [MQTT](mqtt.md) |
| `service_calls.dry_run` | `false` | Log service calls instead of sending them. See [Calling services](calling-services.md#dry-run) |
| `apps.<id>.options` | none | An app's options. See [Anatomy of an app](apps.md#options) |
| `apps.<id>.log_level` | `log_level` | One app's log level, louder or quieter than the rest. See [Logs](logging.md#one-apps-log-level) |

## Common problems

**`… is not a Stewart setting`.** An environment variable starting with `STEWART_` has a typo, or a single
underscore where two belong.

**A setting in `stewart.yaml` has no effect.** An environment variable for the same setting wins. `make config` shows
the value in effect.
