# Stewart component protocol

The `stewart` Home Assistant integration adds the `stewart/*` commands below to Home Assistant's WebSocket API.
Stewart's broker uses them to create and drive its own entities and to answer commands on them. This file is the
reference both sides implement; every exchange has a golden in [`tests/protocol/`](tests/protocol/README.md).

Protocol version: **1**.

## Envelope

Messages use Home Assistant's WebSocket framing. The client adds `id`; a reply is a `result` frame with `success`
and either `result` or `error {code, message}`; session events arrive as `event` frames with the subscribe's `id`.
The shapes below and the goldens leave `id` out.

Every command requires an administrator; anyone else gets Home Assistant's `unauthorized`.

## Versioning

- `protocol` is an integer, separate from the Stewart and component versions.
- The component accepts exactly one protocol. Stewart reads it with `stewart/version` and sends its own with
  `stewart/session/subscribe`, which fails with `protocol_mismatch` when they differ.
- Version 1 stays open until the first release that ships the component. After that, any change to a message shape,
  an error code or a platform rule bumps it.

## Identity

| Value | Format |
|---|---|
| `instance` | `[a-z][a-z0-9_]*`, at most 64 characters. Stewart's `expose.instance`, `default` unless set. |
| `app` | A Stewart app id: `[a-z][a-z0-9_-]*`. |
| `key` | `[a-z][a-z0-9_]*`, at most 64 characters, unique within the app. |
| unique_id | `stewart-<instance>-<app>-<key>`. Neither `instance` nor `key` contains `-`, so the id parses back. |

An entity is addressed by `instance`, `app` and `key`; the platform is not part of its identity.

## Devices

| Device | Identifier | Name | Parent |
|---|---|---|---|
| Hub | `("stewart", "<instance>")` | `Stewart` (`Stewart <instance>` when not `default`) | — |
| App | `("stewart", "<instance>/app/<app>")` | `Stewart · <app>` | Hub |
| Named | `("stewart", "<instance>/device/<identifier>")` | from `device.name` | Hub |

An entity belongs to its app's device unless the upsert carries `device`:

| Field | Type | Notes |
|---|---|---|
| `identifier` | string | `[a-z][a-z0-9_]*`. Apps naming the same identifier share the device. |
| `name` | string | Required. |
| `manufacturer` | string? | |
| `model` | string? | |
| `suggested_area` | string? | Used only when the device is created. |

A device left without entities is removed, and the hub goes with its last device.

## Session

### `stewart/session/subscribe`

| Field | Type | Notes |
|---|---|---|
| `instance` | string | |
| `protocol` | int | Must equal the component's. |
| `stewart_version` | string | For logs and diagnostics. |
| `command_timeout` | float | Seconds the component waits for a command answer; `0 < t ≤ 300`. |

Result: `null`, then events.

- One live session per instance. A new subscribe for the same instance takes over: the old subscription gets a
  `session_replaced` event, its pending commands fail, and it sends nothing more.
- When the session ends (unsubscribe, socket closed, takeover), every entity of the instance becomes unavailable.
- An entity is available while its instance has a session and its own `available` flag is `true`.

### Events

| `event.type` | Fields | Notes |
|---|---|---|
| `command` | `command_id`, `app`, `key`, `action`, `data`, `context` | See [Commands](#commands). `command_id` is an opaque string; `context` is the service call's `{id, parent_id, user_id}`. |
| `session_replaced` | — | The last event of a session that another subscribe took over. |
| `sentence` | — | Reserved. |

## Messages

### `stewart/version`

No fields. Result: `{component_version: string, protocol: int}`. Needs no session.

### `stewart/entity/upsert`

| Field | Type | Notes |
|---|---|---|
| `instance`, `app`, `key` | string | |
| `platform` | string | See [Platforms](#platforms). |
| `config` | object | The platform's config keys. Replaces the previous config. |
| `device` | object? | See [Devices](#devices). Absent: the app's device. |
| `state` | per platform? | Absent: keep the current or restored state. `null`: unknown. |
| `attributes` | object? | Extra state attributes, JSON values. Absent: keep; present: replace. |
| `available` | bool? | Absent: `true` for a new entity, unchanged otherwise. |

Result: `{entity_id, state, attributes, available}`, the entity as it stands after the upsert. Home Assistant picks
`entity_id`, and newer releases put the area in it; Stewart reads it from the result rather than predicting it.

- A new unique_id creates the entity and its registry entry; an existing one updates it in place.
- An existing entity under another platform is removed with its registry entry first; the new one may get another
  entity id.
- A restored entity (after a Home Assistant restart) keeps its restored state and attributes unless the upsert
  sends them. A restored state that no longer fits the config becomes unknown.

### `stewart/entity/state`

| Field | Type | Notes |
|---|---|---|
| `instance`, `app`, `key` | string | |
| `state` | per platform? | Absent: unchanged. |
| `attributes` | object? | Absent: unchanged; present: replaces the set. |
| `available` | bool? | Absent: unchanged. |

Result: `null`. An unknown entity fails with `not_found`.

### `stewart/entity/remove`

Fields: `instance`, `app`, `key`. Result: `{removed: bool}`; `false` when there was nothing to remove.

### `stewart/entity/reconcile`

| Field | Type | Notes |
|---|---|---|
| `instance` | string | |
| `keep` | list of `{app, key}` | Entities to leave alone. |
| `keep_apps` | list of string | Apps whose entities are all left alone, such as apps that failed before exposing. |

Removes every entity of the instance that neither `keep` nor `keep_apps` names, with its registry entry and any device
left empty. Result: `{removed: [entity_id, …]}`.

### `stewart/command/result`

| Field | Type | Notes |
|---|---|---|
| `command_id` | string | From the `command` event. |
| `ok` | bool | |
| `message` | string? | Shown to the user when `ok` is `false`. |

Result: `null`. A `command_id` that is unknown, already answered or timed out fails with `not_found`.

`stewart/entity/*` and `stewart/command/result` need a session for the instance on the same connection, otherwise
they fail with `no_session`. `stewart/command/result` takes the instance from the command.

## Platforms

Every platform takes these config keys:

| Key | Type | Notes |
|---|---|---|
| `name` | string? | Entity name under its device. Absent: the key. |
| `icon` | string? | `mdi:*`. |
| `entity_category` | `config` \| `diagnostic`? | |
| `enabled_by_default` | bool? | Default `true`. |

| Platform | State | Extra config | Actions |
|---|---|---|---|
| `sensor` | number \| string \| null | `device_class`, `unit_of_measurement`, `state_class` (`measurement`, `total`, `total_increasing`), `suggested_display_precision`, `options` (with `device_class: enum`) | — |
| `binary_sensor` | bool \| null | `device_class` | — |
| `switch` | bool \| null | `device_class` (`outlet`, `switch`) | `turn_on`, `turn_off` |
| `button` | none; `state` must be absent and reads back as `null` | `device_class` (`identify`, `restart`, `update`) | `press` |
| `number` | number \| null | `min`, `max`, `step` (required), `mode` (`auto`, `box`, `slider`), `device_class`, `unit_of_measurement` | `set_value {value: number}` |
| `select` | string \| null | `options` (required, non-empty list of strings) | `select_option {option: string}` |
| `text` | string \| null | `min` (default 0), `max` (default 255), `pattern`, `mode` (`text`, `password`) | `set_value {value: string}` |
| `time` | `HH:MM:SS` \| null | — | `set_value {value: "HH:MM:SS"}` |
| `date` | `YYYY-MM-DD` \| null | — | `set_value {value: "YYYY-MM-DD"}` |
| `datetime` | ISO 8601 with offset \| null | — | `set_value {value: string}` |

- `device_class`, `unit_of_measurement` and `state_class` take Home Assistant's values for the platform; the
  component validates them, the unit and `state_class` against the `device_class` too, and fails with
  `invalid_config`. Unknown config keys fail the same way.
- A `sensor` state is a number when the config has `unit_of_measurement`, `state_class`,
  `suggested_display_precision` or a numeric `device_class`. With `device_class` `timestamp` it is ISO 8601 with an
  offset, with `date` it is `YYYY-MM-DD`, and with `enum` one of `options`.
- A state of the wrong type, or outside `min`/`max`/`options`/`pattern`, fails with `invalid_state`.
- `switch` `toggle` reaches Stewart as `turn_on` or `turn_off`.

## Commands

1. A service call on a Stewart entity makes the component send a `command` event on the instance's session and wait
   up to `command_timeout`.
2. Stewart answers with `stewart/command/result`.
3. `ok: true`: the component applies the state the action asked for (`turn_on` → `true`, `set_value` → `value`,
   `select_option` → `option`; `press` changes nothing) and the service call succeeds.
4. `ok: false`: the service call fails with `HomeAssistantError(message)`; the state is unchanged.
5. No answer in time, or the session ends first: the service call fails with `HomeAssistantError`; the state is
   unchanged.

Without a session, a service call fails at once. A `button` records its press time before the command is sent, so a
refused press still shows as pressed.

## Errors

| Code | Raised by | When |
|---|---|---|
| `unauthorized` | any | The user is not an administrator. Home Assistant's own code. |
| `invalid_format` | any | A field is missing or has the wrong type. Home Assistant's own code. |
| `protocol_mismatch` | `session/subscribe` | `protocol` differs from the component's. |
| `no_session` | `entity/*`, `command/result` | The connection holds no session for the instance. |
| `not_found` | `entity/state`, `command/result` | Unknown entity, or a command that is unknown, answered or timed out. |
| `invalid_config` | `entity/upsert` | The config does not fit the platform. |
| `invalid_state` | `entity/upsert`, `entity/state` | The state does not fit the platform or its config. |

## Goldens

See [`tests/protocol/README.md`](tests/protocol/README.md).
