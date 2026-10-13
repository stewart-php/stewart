# ADR carry-over (temporary)

Facts from the deleted ADRs 0029–0035 and 0055–0057 that still match the code
(the named classes exist as of 0.11.1). Step 9 of `DOCS_PLAN.md` moves each into
the developer page named on its line as a short "Why" note, then deletes this file.
Protocol version numbers, dates and "amends ADR n" notes are dropped on purpose.

## Generated entities by ID → architecture.md (codegen)
- `<Domain>Entities::getEntity(string|EntityId)` is the only lookup; per-entity properties were removed so an ID is searchable in a project.
- Only IDs seen by the last `stewart generate` are accepted; others throw `IdentifierError::EntityNotGenerated`. No static check on the ID, because IDs from app options are plain strings.
- Completion comes from `.phpstorm.meta.php`, not a literal-union `@param`.

## Shared trigger subscriptions → protocol.md (triggers)
- `TriggerSubscribers` counts subscriptions per canonical-JSON spec key: one HA `subscribe_trigger` for the first, one unsubscribe after the last.
- `HaTriggerLink` re-issues live specs on every connect; fires during an outage are lost, not replayed.
- An HA refusal affects that spec only; the worker cancels the subscription.

## Local sun calculation → architecture.md (packages: sun)
- NOAA equations with Astral's refraction and horizon dip, so times match HA's sun integration within seconds. Schedules keep working while HA is unreachable.
- Location comes from HA `get_config` at boot; a change applies on restart. Without it `UnlocatedSunCalendar` throws `SunError::LocationUnknown`.
- `SunEventSchedule` is a wall-clock schedule, so missed runs and clock steps behave as for other schedules. Polar day/night waits for the next date the event occurs.

## Fired events share the call budget → protocol.md (service calls)
- `fireEvent()` and service calls share one in-flight budget (`HaCallSlots`, `service_calls.max_in_flight[_per_worker]`) and one worker `PendingRequests` registry.
- Under `service_calls.dry_run` a fired event is logged and answered with a `dry-run:` context. HA only lets admins fire events.

## Registry mirrored to workers → architecture.md + protocol.md
- The broker reads the five registries on every connect and re-reads after a 1s quiet period following any `*_registry_updated`; a failed read keeps the previous registry.
- Order to a new worker: `Bootstrap`, `RegistrySnapshot`, `StateSnapshot`. `RegistryCache` keeps the highest revision; lookups never suspend.
- Resolution follows HA: entity area else device area; labels of entity, device and area. Moving an entity is seen by a watch only on its next state change.

## App pause is a gate → architecture.md (control) + user guide status.md
- A paused app stays initialized; the worker drops deliveries and skips schedule firings, with no catch-up on resume. A running handler finishes.
- Source of truth is the broker's `AppPauseRegistry`; changes go through `AppPauseService` (sources `config`, `control`, `http`).
- Overrides persist under `<prefix>:runtime:app-pause:<id>` and beat `apps.<id>.paused`; `app:reset` removes one. A failed override load stops the daemon.

## Git releases switch by restart → architecture.md (deploy)
- The entrypoint owns git, Composer, the release layout (`/app/releases/<sha>`, `current`, `next`) and the restart loop; the broker owns the poll timer, validation and status.
- A new commit must pass `stewart check` in a subprocess; a rejected commit is remembered and never retried.
- Switching is a graceful stop with exit code 75; the entrypoint swaps `next` over `current` and starts PHP again. `tini -g` is not used, so the broker can shut workers down itself.

## Exposed entities via an HA integration → component-protocol.md
- A custom integration (domain `stewart`) adds `stewart/*` WebSocket commands over the broker's existing session; MQTT discovery was not used because it needs a broker and loses state on its restart.
- Detected on each connect via `stewart/version`; missing or mismatched gives `ExposureError::ComponentMissing`/`ProtocolMismatch`. The integration protocol is an integer separate from the Stewart version.
- unique_id `stewart-<instance>-<app>-<key>`; `instance` and `key` match `[a-z][a-z0-9_]*`.

## One session subscription carries commands → component-protocol.md
- One `stewart/session/subscribe` per connection carries every command; the newest subscribe for an instance wins and the old one gets `session_replaced`.
- When the session ends, all entities of the instance become unavailable.
- The component applies the requested state only after `ok`, so HA never shows a state the app refused.

## Commands route through the broker → component-protocol.md
- The broker refuses at once when no running app exposes the entity, the app is paused, or its worker is gone.
- `watchCommands()`: the first returning callback accepts; `CommandException::rejected()` rejects. A command nobody watches is accepted.
- A command dropped by a stream operator is not answered and times out (`expose.command_timeout`).
