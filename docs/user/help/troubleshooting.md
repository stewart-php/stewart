---
title: Troubleshooting
description: Find the cause of a problem by its symptom, from a failed connection to an app that does not react.
sidebar:
  order: 1
---

Start with two commands. `make logs` (or `make run` in the foreground) shows what Stewart says, and `make status`
shows each app's state and last failure. Most problems name themselves in one of them.
On a [Git](../deploy/git.md) or [image](../deploy/image.md) server, which has no Makefile, use
`docker compose -f compose.git.yaml logs -f stewart` and `… exec stewart stewart status` instead.

## Stewart does not start

**`The child config "token" under "stewart.home_assistant" must be configured`.** `.env` has no token, or there is no
`.env`. Run `make env`, then fill in the token. See [Install Stewart](../start/install.md#4-connect-to-home-assistant).

**`… is not a Stewart setting`.** An environment variable starting with `STEWART_` has a typo, or one underscore
where two belong. See [Configuration](../guides/configuration.md#environment-variables).

**The store is unreachable.** With `persistence.url` set, Stewart checks the store at start. Check that the `valkey`
container runs (`docker compose ps`).

**`STEWART_CONTROL__TOKEN` is missing.** The control socket needs a token. `make env` creates `.env` with one; on an
older `.env`, add `STEWART_CONTROL__TOKEN=` followed by `openssl rand -hex 16`.

## Stewart cannot reach Home Assistant

**It keeps reconnecting.** Check the URL from inside the container: `make sh`, then
`wget -qO- http://your-host:8123`. `localhost` is the container itself; use Home Assistant's host name or IP, or
`host.docker.internal` when it runs on the same machine.

**`AdministratorRequired`.** The token belongs to a user without administrator rights.

**It worked, then stopped after a restart of Home Assistant.** Stewart reconnects on its own, waiting up to a minute
between tries (`reconnect.max_delay`). `make status` shows the connection on its first table.

## An app does not start

`make status` shows the app as failed, with the reason; the log has the full message.

| Message | Fix |
|---|---|
| `… matches no parameter of …::__construct()` | An option in `stewart.yaml` has a name the constructor does not have |
| `needs option "…"` | Set the option, or give the parameter a default |
| `Persistence is not configured` | Set `STEWART_PERSISTENCE__URL` |
| `MQTT is not configured` | Set `STEWART_MQTT__URL` |
| `A … schedule needs a location` | Set a location in Home Assistant under Settings → System → General |
| `… is not a generated … entity` | Fix the entity ID, or run `make generate` |

**The app is not listed at all.** The class must be in `apps/`, implement `App`, and carry `#[Automation]`. The log
warns about a class without the attribute, and about a file that fails to load.

The [error reference](../reference/errors.md) lists every message.

## An app does not react

1. **Check the entity ID** under **Developer tools → States** in Home Assistant.
2. **Check the app runs**: `make status` shows it as `running`, not `paused` or failed.
3. **Check the operator.** `whenChangedTo('on')` ignores changes from `unavailable`, and a first state is not a
   change. A `filter()` before a time window can hide the change the window needs; see
   [Put time windows first](../guides/state-changes.md#put-time-windows-first).
4. **Add a log line** at the start of the handler, and run with `make run ONLY=<app-id>`.
5. **Write a test** that pushes the state you expect; it shows whether the app or the input is wrong.

## Service calls fail

**`was rejected by Home Assistant`.** Try the same call under **Developer tools → Actions**; Home Assistant's reason is
in the message.

**`could not reach Home Assistant`.** Stewart was reconnecting at that moment.

**`was refused`.** Too many calls waited for an answer at once. Call one service with several targets instead of one
call per entity.

**The call succeeds but nothing happens.** Dry run may be on: the log says `service_calls.dry_run is on`. Remove
`DRY_RUN=1` or `STEWART_SERVICE_CALLS__DRY_RUN`.

## Apps are slow, or events are dropped

**`… dropped …` warnings naming a worker.** A handler blocks the worker, so events pile up. Look for `sleep()`, a slow
HTTP request, or a long loop, and replace it with a schedule, a wait, or a non-blocking client.

**A worker restarts again and again, then stays down.** After too many restarts in a minute, Stewart stops the worker
(`quarantined` in `make status`). Fix the cause in the log, then run
`docker compose exec stewart stewart worker:restart <number>`.

## Exposed entities

**`ComponentMissing`.** The Stewart integration is not installed in Home Assistant, or Home Assistant was not
restarted after installing it. See [Exposed entities](../guides/exposed-entities.md#setting-it-up).

**`ProtocolMismatch`.** Stewart and the integration have different versions.

## Deploying

**A pushed commit does not go live.** In Git mode, a commit that fails `stewart check` is rejected and the old one
keeps running. `make status` shows it on the `deploy` row; the log says why.

**`make status` cannot connect on Docker Desktop.** macOS and Windows cannot share the control socket with the host.
Run it inside the container instead: `docker compose exec stewart stewart status`.

## Still stuck?

Open an issue at [github.com/stewart-php/stewart](https://github.com/stewart-php/stewart/issues) with the log lines,
`make status`, and the app's code.
