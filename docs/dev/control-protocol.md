---
title: 'Control protocol'
description: 'The control socket that stewart status and the app and worker commands use to talk to a running broker.'
---

A running broker listens on a control socket. `stewart status`, `app:pause`, `app:resume`, `app:reset` and
`worker:restart` connect to it, ask one thing, and disconnect. The HTTP admin API answers the same requests through
the same `RuntimeAdmin`.

## Where it listens

`control.listen` sets the address: `unix://var/run/stewart.sock` by default (relative to the project root), or
`tcp://host:port`. `off` disables it. Every connection must present `control.token`; the broker refuses to start with
the socket on and no token. It logs a warning when it listens on a non-loopback TCP address.

## Frames

One JSON object per line, with a `type`. Instants are ISO-8601 text in UTC, and durations are whole microseconds
under a key ending in `_us`.

A session is always:

1. The client sends `hello` with the token, a client name and the protocol version.
2. The broker answers `welcome`, or `rejected` with a reason (wrong token, other protocol version) and closes.
3. The client sends one request.
4. The broker answers it, sends `bye`, and closes.

| Client frame | Server answer |
|---|---|
| `hello` `{token, client, protocol}` | `welcome` `{protocol}` or `rejected` `{reason}` |
| `snapshot_request` | `snapshot` `{snapshot}`: the whole `RuntimeSnapshot` |
| `pause_app`, `resume_app`, `reset_app` `{app_id}` | `command_result` `{changed, message, warning}` |
| `restart_worker` `{worker_id}` | `command_result` |
| (any request that fails) | `request_failed` `{reason, message}` |
| | `bye` `{reason}` |

The frames are `final readonly` classes in `Stewart\Runtime\Control\Protocol\Frame`, encoded by
`Control\Protocol\Codec\FrameCodec`. `ControlRequestDispatcher` answers the requests in one `match` over
`RuntimeAdmin`. Every frame has a golden in `packages/runtime/tests/Fixtures/Control/Frames`.

## Versioning

`ControlProtocol::VERSION` is a single integer, separate from the Stewart version. Both sides must use the same
number; a mismatch is rejected at `hello`, so a `stewart` command from another release fails clearly instead of
misreading a snapshot. Change it whenever a frame or the snapshot changes shape; the frame goldens fail until you do.

## Talking to it by hand

Without PHP, with the current version in place of `<version>`:

```bash
printf '{"type":"hello","token":"%s","client":"socat","protocol":<version>}\n{"type":"snapshot_request"}\n' "$STEWART_CONTROL__TOKEN" \
  | socat - UNIX-CONNECT:var/run/stewart.sock
```

`stewart status --json` prints the same snapshot, and is the stable way for scripts to read it.
