---
title: 'Component protocol'
description: 'How the broker talks to the stewart Home Assistant integration: the sequences and how they fail.'
sidebar:
  order: 4
---

How the broker drives the `stewart` Home Assistant integration to expose entities and answer commands. The message
shapes, error codes and per-platform rules live in
[PROTOCOL.md](https://github.com/stewart-php/stewart/blob/main/integrations/home-assistant/PROTOCOL.md) next to the
component.

---

## Connect

Every `stewart/*` command rides the broker's existing WebSocket session, after the core subscriptions and the state
seed. On each connect the broker:

1. sends `stewart/version`. An `unknown_command` error means no component; a `protocol` other than Stewart's means a
   mismatch. Either way exposure is off for this connection, and `stewart status` says why.
2. sends `stewart/session/subscribe` with its instance, protocol, version and `command_timeout`. From here the
   component treats the instance as live.
3. replays every exposed entity (below).

The component checks the protocol a second time on subscribe, so a component upgraded between the two calls fails
with `protocol_mismatch` instead of misreading a message.

---

## Exposing an entity

An app calls `expose*()` on its `EntityExposure`. The worker sends the request to the broker, which keeps it per app
and sends `stewart/entity/upsert`. The component creates the entity, or updates it if the unique_id exists, and answers
with the entity id, state, attributes and availability it ended up with. That answer seeds the handle: an upsert
without `state` keeps what Home Assistant restored, so `getValue()` survives a restart of either side.

Later changes go out as `stewart/entity/state`. It carries only what changed; `attributes`, when present, replaces the
whole set. Exposed entities are ordinary Home Assistant entities, so their states come back through `state_changed`
like any other.

An upsert for an app and key that exist under another platform replaces the entity. Its entity id may change.

---

## A command

```
HA action call ─▶ component ──event command──▶ broker ──IPC──▶ worker handler
                     │ waits                                       │
                     ◀──── stewart/command/result {ok, message} ◀──┘
```

The component gives each command an id and waits up to `command_timeout`. A handler that returns answers `ok: true`,
and the component applies the requested state. A handler that throws `CommandException::rejected()` answers `ok: false` with its
message, and the action call fails with that message. The broker answers for a paused app itself, with `ok: false`.

---

## Reconnect and replay

The component holds no list of apps. When the WebSocket drops, the subscription ends and every entity of the instance
becomes unavailable. On reconnect the broker opens a new session and sends one upsert per exposed entity with its last
known state and availability, which brings them back.

A broker that restarts while Home Assistant still holds the old socket does not wait: the newest subscribe for an
instance takes over, and the old subscription gets a final `session_replaced` event.

---

## Reconcile

Entities live in Home Assistant's registry after their app is gone. Once after startup the broker sends
`stewart/entity/reconcile` with every app and key that running apps exposed. The component removes the rest of the
instance's entities and lists their ids. Entities of failed or quarantined apps are in the keep list, so they stay,
unavailable. `expose.prune: false` skips the call.

---

## Failure modes

| What happens | How it surfaces | What happens next |
|---|---|---|
| The component is not installed | `unknown_command` on `stewart/version` | Exposure off; `expose*()` throws `ExposureError::ComponentMissing` |
| Stewart and the component disagree on the protocol | `protocol` differs, or `protocol_mismatch` on subscribe | Exposure off; `expose*()` throws `ExposureError::ProtocolMismatch` |
| The session ends | Home Assistant closes the subscription | Every entity of the instance becomes unavailable until the replay |
| A handler is too slow | No `stewart/command/result` within `command_timeout` | The action call fails; a late answer gets `not_found` |
| The worker dies during a command | The broker loses the owner | The broker answers `ok: false`; the app's entities become unavailable |
| A config or state does not fit the platform | `invalid_config` or `invalid_state` | The upsert or state change is refused; the entity keeps its previous value |

---

## Design decisions

### An integration, not MQTT discovery

Core Home Assistant has no WebSocket command to create an entity. MQTT discovery can, but it needs an MQTT server in
every installation, splits commands from the session Stewart already holds, and loses state when that server
restarts. A small custom integration, as hass-node-red uses, adds `stewart/*` commands to the existing session.

- It lives in `integrations/home-assistant/` and releases with everything else; the wire protocol is an integer of
  its own, and the component accepts exactly one, so a version skew fails at connect, not in the middle of a session.
- Every `stewart/*` command needs an administrator, as Stewart's token already must.
- The unique ID is `stewart-<instance>-<app>-<key>`. `instance` and `key` allow `[a-z][a-z0-9_]*` only, so the ID
  still parses although app IDs contain `-`. Renaming an app creates new entities.

### One session subscription carries every command

A subscription gives both a push channel to Stewart and a reliable signal that Stewart is gone: it ends when the
connection does.

- The newest subscribe for an instance wins, so a restarted broker never waits for Home Assistant to notice a dead
  socket. Two Stewarts sharing an instance name steal the session from each other; the log shows each
  `session_replaced`.
- The component applies a command's state only after `ok`, so Home Assistant never shows a state the app refused.

### Commands route through the broker

- The broker refuses at once, with `ok: false`, when no running app exposes the entity, the app is paused, or its
  worker is gone. A dying worker's pending commands are refused at once.
- In the app, the first subscriber callback that returns accepts. A command nobody watches is accepted, so a switch
  works as a stored toggle. A command an operator drops, or `map()`s away, is never answered and times out after
  `expose.command_timeout`.
- Commands for one entity run one at a time; a handler may await.
- `stewart_app_exposed_commands_total{outcome}` counts accepted, rejected, refused and lost commands per app.

---

## Testing it

Every exchange has a golden in `integrations/home-assistant/tests/protocol/`: one JSON file with the request and its
result, error or event, without the WebSocket `id`. The Python tests feed each request to the component and compare
the answer; the PHP tests replay the same files through `FakeHaServer`. A shape change shows up on both sides at once.
