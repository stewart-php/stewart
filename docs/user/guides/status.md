---
title: Checking the daemon
description: What stewart status shows, how to pause an app, how to script it, HTTP health checks, and how to reach a daemon on another host.
sidebar:
  order: 11
---

Stewart serves a small control socket, and `stewart status` reads it:

```bash
make status      # daemon, workers and apps as tables, then exit
```

It needs the token from `.env`:

```bash
STEWART_CONTROL__TOKEN=…      # openssl rand -hex 16 makes a good one
```

The daemon refuses to start while the socket is on and no token is set. To run
without the socket, set `STEWART_CONTROL__LISTEN=off` (the word, not an empty
value: an empty variable means "unset").

## What you see

| Table | Shows |
|---|---|
| Daemon | Version, uptime, memory, the Home Assistant connection and its last outage, store health, the deployed commit and its last rejected one, routing figures, service calls in flight and refused. |
| Workers | Phase, pid, readiness, loop lag, memory, how long ago the worker last answered a ping, missed probes, restarts inside the window (and since start, once there were any), queued and dropped messages, state batches sent. |
| Apps | State (`(paused by control 2m ago)` when paused, naming `config` or `control`; `(resumed by control 5m ago over config)` when a resume beats `apps.<id>.paused: true`), subscriptions, schedules, exposed entities (unavailable ones included), events delivered and dropped inside the worker, events and schedule runs suppressed while paused, schedule runs, publishes, service calls succeeded and failed, the bucket the 95th-percentile call latency falls in, failures. |
| Failures | Each app's last failure: when, in which phase, the reason and the message. |

Worker and app numbers arrive with each ping, so they are as fresh as
`supervision.ping_interval` (10 s by default). The counters add up across
worker restarts for as long as the daemon runs. With pings off, an app's state
reads `unknown`.

## Pausing an app

A paused app stays loaded, but skips its deliveries and schedule runs until it is
resumed. A handler already running finishes. Nothing is replayed on resume.

```bash
vendor/bin/stewart app:pause porch      # App porch paused.
vendor/bin/stewart app:resume porch     # App porch resumed.
vendor/bin/stewart app:reset porch      # App porch pause override removed; it is running.
kubectl exec deployment/home-stewart -- stewart app:pause porch
```

All three take the same `--address`, `--token` and `--timeout` as `status`.
Repeating a command is harmless and still exits 0. An unknown app, or one
switched off with `apps.<id>.enabled: false`, exits 1. To start an app paused,
set `apps.<id>.paused: true`.

With `persistence.url` set, every `app:pause` and `app:resume` is stored and
survives restarts, and it beats `apps.<id>.paused` in config. `app:reset` forgets
the stored choice so config decides again; it also removes the choice stored for
an app that is no longer loaded. Without a store, or while the store is down, the
change still applies and a `Not saved:` warning on stderr says it lasts until the
daemon restarts.

## Restarting a worker

```bash
vendor/bin/stewart worker:restart 1     # Worker 1 is restarting.
```

`worker:restart` takes the worker number from the Workers table and the same
options as `status`. A running worker gets `shutdown_grace` to dispose its apps,
then a new process starts; a quarantined worker, or one waiting out its backoff,
starts at once. Either way it gets its full `supervision.restart_attempts` back.
The command answers once the restart has begun; `stewart status` shows the
`stopping` and `spawning` phases on the way. Asking again while the worker is
still starting or stopping exits 0 and changes nothing; an unknown worker exits 1.

## Scripting

```bash
vendor/bin/stewart status --json | jq '.snapshot.apps[] | {id, state, counters}'
```

The JSON is the snapshot exactly as it crosses the socket, and also lists every
routed subscription. The exit status is 1 when the daemon is down or the token
is wrong, so it works as a health check.

## Health checks

For an orchestrator, the daemon can answer probes over plain HTTP, without
starting PHP or knowing the control token. The port is off by default:

```yaml
http:
  listen: tcp://0.0.0.0:8080      # an IP, not a host name; tcp://[::]:8080 for IPv6
```

| Path | 200 | 503 |
|---|---|---|
| `/healthz` | The daemon answers; nothing else is checked | never |
| `/readyz` | Home Assistant connected, at least one worker running its apps, no worker quarantined | Still connecting or reconnecting, no worker running (starting up, or every worker restarting), or a worker quarantined |

```bash
curl -s localhost:8080/readyz   # {"status":"not_ready","reasons":["Home Assistant is connecting"]}
```

The body is `{"status":"alive|ready|not_ready","reasons":[…]}`. Only `GET` and
`HEAD` are accepted (405 otherwise); any other path is 404, and a failure while
building the answer is a 500 with an error in the log. The port starts with the
control socket, after the store check, so it is closed during that first step.
The Helm chart turns it on and probes it by default; `stewart status
--probe=liveness|readiness` gives the same verdicts over the control socket.

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

`GET /metrics` on the same port answers the Prometheus text format, built from
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
vendor/bin/stewart status --address tcp://homeassistant.local:7333 --token …
```

With both `--address` and `--token` given, no `stewart.yaml` or `.env` is read,
so the command runs on a machine that has neither. The broker logs a warning
when it listens off loopback: anyone with the token reads the runtime snapshot
and can pause apps.

Docker Desktop on macOS or Windows cannot share a unix socket through a bind
mount, so `make status` from the host needs `tcp://` there too.

## Wire

One JSON object per line. A client sends `hello`, waits for `welcome`, then
sends one request; the broker answers it and sends `bye`, then closes. Instants are ISO-8601 text in
UTC and durations are whole microseconds under a `_us` key. Without PHP:

```bash
printf '{"type":"hello","token":"%s","client":"socat","protocol":18}\n{"type":"snapshot_request"}\n' "$STEWART_CONTROL__TOKEN" \
  | socat - UNIX-CONNECT:var/run/stewart.sock
```
