---
title: Status and control
description: See how Stewart and its apps are doing, pause an app, restart a worker, and add health checks and metrics.
sidebar:
  order: 17
---

A running Stewart answers questions about itself through a **control socket**: a private connection that
`stewart status` and the other commands on this page use. `make status` asks it:

```bash
make status      # Stewart, its workers and its apps, as tables
```

It needs the control token from `.env`, which `make setup` creates:

```bash
STEWART_CONTROL__TOKEN=…      # openssl rand -hex 16 makes a new one
```

Stewart refuses to start without a token while the socket is on. To run without the socket, set
`STEWART_CONTROL__LISTEN=off` (the word: an empty variable means "not set").

The other commands on this page run inside the Stewart container:

| Where Stewart runs | Prefix |
|---|---|
| `make up` on your machine or a server checkout | `docker compose exec stewart stewart …` |
| A [Git](../deploy/git.md) or [image](../deploy/image.md) server | `docker compose -f compose.git.yaml exec stewart stewart …` (or `compose.image.yaml`) |
| Kubernetes | `kubectl exec deployment/<release>-stewart -- stewart …` |

## What you see

| Table | Shows |
|---|---|
| Daemon | Stewart's version, uptime and resident memory; the Home Assistant connection and its last outage; whether the store is up; the `component` row (the Stewart integration in Home Assistant); the `deploy` row (the running commit and the last rejected one, in Git mode); service calls waiting and refused. |
| Workers | Whether each worker is running, how much resident memory it uses, how often it restarted, and whether it is falling behind (events waiting or dropped). |
| Apps | Whether each app is running, paused (and by whom: `config`, `control` or `http`) or failed; how many events and schedule runs it handled or skipped while paused; its exposed entities; how its service calls went. |
| Failures | Each app's last failure: when, where, the reason and the message. |

The worker and app figures are refreshed every `supervision.ping_interval` (10 seconds by default), and keep adding up
across worker restarts until Stewart itself restarts. `stewart status --json` holds every field.

## Pausing an app

A paused app stays loaded, but receives nothing and skips its schedule runs until it is resumed. A handler already
running finishes. Nothing is replayed on resume.

```bash
docker compose exec stewart stewart app:pause porch-light    # App porch-light paused.
docker compose exec stewart stewart app:resume porch-light   # App porch-light resumed.
docker compose exec stewart stewart app:reset porch-light    # Forgets the pause; stewart.yaml decides again.
```

Repeating a command is harmless and still succeeds. An unknown app, or one switched off with
`apps.<id>.enabled: false`, fails. To start an app paused, set `apps.<id>.paused: true`.

With persistence set up, a pause or resume is stored and survives restarts, and it wins over `apps.<id>.paused`.
`app:reset` forgets the stored choice, also for an app that no longer exists. Without a store, the change still
applies, and a `Not saved:` warning says it lasts until Stewart restarts.

All three accept the options described under [Another host](#another-host).

## Restarting a worker

```bash
docker compose exec stewart stewart worker:restart 0    # Worker 0 is restarting. Workers are numbered from 0.
```

The number comes from the Workers table. A running worker gets `shutdown_grace` to stop its apps, then a new one
starts. A worker that Stewart gave up on after repeated crashes (`quarantined`), or one waiting to retry, starts at
once, with its crash count reset. The command returns once the restart has begun; `stewart status` shows the worker
`stopping`, then `spawning`.

## Scripting

```bash
docker compose exec stewart stewart status --json | jq '.snapshot.apps[] | {id, state, counters}'
```

The JSON holds everything the tables show, and every entity and event each app listens to. The command exits with 1
when Stewart is down or the token is wrong, so it also works as a health check.

## Health checks

For Docker, Kubernetes or a monitoring tool, Stewart can answer health checks over plain HTTP, without the control
token. The port is off by default:

```yaml
http:
  listen: tcp://0.0.0.0:8080      # an IP address, not a host name; tcp://[::]:8080 for IPv6
```

Publish the port in your compose file (`ports: ["8080:8080"]`) to reach it from outside the container.

| Path | 200 | 503 |
|---|---|---|
| `/healthz` | Stewart answers | never |
| `/readyz` | Connected to Home Assistant, at least one worker running its apps, no worker quarantined | Still connecting or reconnecting, no worker running yet, or a worker quarantined |

```bash
curl -s localhost:8080/readyz   # {"status":"not_ready","reasons":["Home Assistant is connecting"]}
```

The body is `{"status":"alive|ready|not_ready","reasons":[…]}`. The port opens only after Stewart has reached Valkey
at start. The Helm chart turns it on and uses it by default; `stewart status --probe=liveness` or
`--probe=readiness` gives the same answers through the control socket.

## Admin API

A second HTTP port lists, pauses and resumes apps, and restarts workers, for
scripts and Home Assistant. It is off by default and always needs a bearer token:

```yaml
http:
  admin:
    listen: tcp://127.0.0.1:8081   # an IP; 0.0.0.0 to reach it from other hosts
```

Set the token from the environment, `STEWART_HTTP__ADMIN__TOKEN`; the daemon
refuses to start without one.

| Request | Answer |
|---|---|
| `GET /api/apps` | `{"apps":[{"id","state","paused","pause","config_pause_override"}, …]}` |
| `GET /api/apps/{id}` | One app, same shape |
| `POST /api/apps/{id}/pause`, `/resume`, `/reset` | `{"changed","message","warning"}`, as `app:pause`, `app:resume`, `app:reset` |
| `POST /api/workers/{id}/restart` | The same shape, as `worker:restart` |

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" localhost:8081/api/apps/porch/pause
# {"changed":true,"message":"App porch paused.","warning":null}
```

Repeating a change answers 200 with `changed: false`. A missing or wrong token
is 401, an unknown app or worker 404, a disabled app or a daemon shutting down 409, the wrong method 405; failures
carry `{"reason","message"}`. Changes are recorded with source `http`, so
`stewart status` shows `paused by http`. There is no CORS: a browser page on
another origin cannot send the token. From Home Assistant, a `rest_command`
calls it:

```yaml
rest_command:
  stewart_pause:
    url: "http://stewart.home.svc:8081/api/apps/{{ app }}/pause"
    method: post
    headers:
      authorization: !secret stewart_admin_bearer   # "Bearer <token>"
```

### Metrics

`GET /metrics` on the same port answers the Prometheus text format (every series is in the
[metrics reference](../reference/metrics.md)), built from
the same snapshot as `stewart status`, under the same token. Series are named
`stewart_*`. Per-app series have `app` and `worker` labels, per-worker series
have `worker`, and service-call latency is the histogram
`stewart_app_service_call_duration_seconds{outcome}`. Values that are not known
yet, such as a worker's memory before its first probe, are left out instead of
reported as 0. While `deploy.git.poll` is on, `stewart_deploy_info{commit}` names
the running commit and `stewart_deploy_failures_total` counts commits that failed
`stewart check`. Worker counters arrive with each liveness probe, so they trail by
up to `supervision.ping_interval`.

```yaml
scrape_configs:
  - job_name: stewart
    authorization:
      credentials_file: /etc/prometheus/stewart-admin-token
    static_configs:
      - targets: ["stewart.home.lan:8081"]
```

## Another host

Listen on TCP, and give both sides the same token:

```yaml
control:
  listen: tcp://0.0.0.0:7333
```

```bash
stewart status --address tcp://homeassistant.local:7333 --token …
```

With both `--address` and `--token` given, no `stewart.yaml` or `.env` is read,
so the command runs on a machine that has neither. Stewart logs a warning
when it listens off loopback: anyone with the token reads the runtime snapshot
and can pause apps.

Docker Desktop on macOS or Windows cannot share a unix socket through a bind
mount, so `make status` from the host needs `tcp://` there too.
