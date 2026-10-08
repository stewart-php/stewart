# Protocol goldens

One JSON file per exchange of the [component protocol](../../PROTOCOL.md). The Python tests send each `request` to
the component and compare its answer; the PHP tests replay the same files through `FakeHaServer`.

Every file has `protocol` and exactly one of these forms:

| Form | Keys | Meaning |
|---|---|---|
| Result | `request`, `result` | The command succeeded with this `result`. |
| Error | `request`, `error {code, message}` | The command failed. Tests compare `code`; `message` is an example. |
| Event | `event` | The `event` payload of a session event frame. |

`request` includes `type`; no file holds the WebSocket `id`.

Names: `<message>.json` or `<message>.<case>.json`, where `<message>` is the type without `stewart/` and with `/`
as `-` (`entity-upsert.sensor.json`). Events are `event-<type>.json` or `event-<type>.<case>.json`, with `_` as `-`
(`event-session-replaced.json`).

A changed golden is a protocol change: bump `protocol` in every file and in `PROTOCOL.md` once the component has
shipped.
