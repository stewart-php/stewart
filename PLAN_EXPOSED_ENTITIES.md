# Exposed entities: high-level plan

Apps create and drive their own Home Assistant entities (sensors, switches, numbers, …) and edit the registry of
existing ones. A small Python integration, `stewart`, runs inside Home Assistant and gives Stewart a WebSocket API
for it, the way [hass-node-red](https://github.com/zachowj/hass-node-red) does for Node-RED.

This file is the overview. Each PR below gets its own detailed plan before implementation; the agent writing that
plan reads this file, CLAUDE.md, and the code the PR touches. Delete this file once the last PR lands.

## Decisions

Settled with the owner on 2026-10-08. Detailed plans build on these and do not reopen them.

| Topic | Decision |
|---|---|
| Transport | Own HA custom integration, domain `stewart`, over the broker's existing WebSocket session. No MQTT discovery. |
| Scope | Stewart-owned entities (state, attributes, availability, runtime config, commands); registry edits of any HA entity; conversation sentences. Webhooks and device triggers/actions are out. |
| Repository | `integrations/home-assistant/custom_components/stewart` in this monorepo, mirrored read-only to `stewart-php/hass-stewart` for HACS. Same version as everything else. |
| App API | An injected service returns typed handles: `$this->entities->exposeSwitch($key, $config)` → `ExposedSwitch`. |
| Naming | "Exposed": `Stewart\Contracts\Exposure\EntityExposure`, `ExposedSwitch`, `ExposedEntityKey`; config section `expose.*`. |
| Platforms | Wave 1 sensor + binary_sensor, wave 2 switch + button, wave 3 number, select, text, time, date, datetime. Light/cover/climate later, separately. |
| Identity | unique_id `stewart-<instance>-<app_id>-<key>`; `expose.instance` defaults to `default`. Renaming an app id creates new entities. |
| Devices | A hub device per instance, one child device per app (`Stewart · <app id>`), overridable per entity with `DeviceInfo`. |
| Channel | One session subscription per connection (`stewart/session/subscribe`) carries every command. Entity create, update and remove are plain commands. |
| Stale entities | After startup the broker reconciles: the component removes this instance's entities that no running app exposed. Entities of failed or quarantined apps are kept (unavailable). `expose.prune: false` turns removal off. |
| Availability | Unavailable when the session ends (component side) and when the owning app fails, is quarantined, or its worker restarts (broker side). A paused app stays available, but its commands are refused. Apps can also set availability themselves. |
| Commands | The component waits for Stewart's answer, with a timeout. If the handler returns, the requested state applies; if it throws `CommandRejected`, the HA service call fails with that message. |
| Restore | The component uses HA's Restore* mixins; a handle is seeded from what HA holds, so `getValue()` survives restarts of either side. |
| Enablement | Auto-detected on each connect through `stewart/version`. Without a compatible component, `expose*()` throws `ExposureError::ComponentMissing` / `ProtocolMismatch`; `stewart status` says why. No config switch. |
| Sentences | First a typed `ConversationTrigger` for the existing `watchTrigger()` (core trigger, fixed reply, no component). Then `stewart/sentence` for replies the app computes. |
| Registry edits | A separate injectable `Stewart\Contracts\Registry\RegistryEditor`, going to core `config/entity_registry/update`. Works on any entity; editing a Stewart-exposed one logs a warning pointing at the handle. |
| Python tooling | `ha` service in docker-compose (Python 3.14, pinned `pytest-homeassistant-custom-component`, ruff, mypy). `make ha-check`, included in `make check`. CI runs hassfest; HACS validation runs on the mirror after the split. |
| Contract tests | Shared JSON goldens for every `stewart/*` message, replayed by PHP (`FakeHaServer`) and Python tests. `make test-ha-e2e` boots a real HA with the component; CI only. |
| Minimum HA | 2026.4; CI tests the minimum and the latest. |

## Architecture

```
 App (worker)                Broker                         Home Assistant
 ────────────                ──────                         ──────────────
 EntityExposure ──IPC──▶ ExposureLink ──stewart/entity/*──▶ stewart component
 ExposedSwitch                │  keeps every upsert + last     ├─ platforms (sensor, switch, …)
   ├─ setOn()                 │  state, per app                ├─ Restore* state
   └─ watchCommands() ◀─IPC── │ ◀── session subscription ───── └─ hub + per-app devices
                              │      (command events)
 RegistryEditor ──IPC──▶ HaClient ──config/entity_registry/update──▶ core
```

- **Component.** It is stateless about Stewart apps: entities exist because the broker upserted them, or because HA
  restored them from the registry. It owns entity lifecycle, devices, restore, availability on session end, and the
  command round trip with its timeout.
- **Broker, `ExposureLink`.** It mirrors `HaTriggerLink`: one place holds what each app exposed, replays it after
  every (re)connect, sends availability on `WorkerPoolListener::workerGone` and on quarantine, routes commands to
  the owning worker, refuses commands for paused apps, and runs the startup reconcile once.
- **Worker.** `WorkerEntityExposure` is scoped per app like `WorkerHaContext::forApp()`. Handles are released with the
  app's scope on dispose. Commands reach handlers through an `EventStream`, so the existing operators work on them.
- **Client.** `stewart-php/client` holds the `stewart/*` commands (`Connection\Command\Component`) and their VOs
  (`Stewart\Client\Component`); `HaClient` exposes them as typed methods.
- Exposed entities are ordinary HA entities, so their states reach the state cache, codegen and `watchStateChanges()`
  with no special path. A handle exposes `getEntityId()` once HA has assigned one.

## Component protocol (sketch)

PR 1 fixes the exact shapes. Every command is admin-only, as in hass-node-red.

| Message | Direction | Purpose |
|---|---|---|
| `stewart/version` | → HA | `{component_version, protocol}`; tells Stewart whether the component is there and compatible |
| `stewart/session/subscribe {instance, protocol, stewart_version, command_timeout}` | → HA | Opens the command channel. Events: `command`, later `sentence`. When it ends, the instance's entities become unavailable |
| `stewart/entity/upsert {instance, app, key, platform, config, device?, state?, attributes?, available?}` | → HA | Creates or updates one entity. The result is `{entity_id, state, attributes}`, which seeds the handle |
| `stewart/entity/state {instance, app, key, state?, attributes?, available?}` | → HA | State, attribute or availability change |
| `stewart/entity/remove {instance, app, key}` | → HA | Removes one entity and its registry entry |
| `stewart/entity/reconcile {instance, keep}` | → HA | Removes every entity of the instance not in `keep`; the result lists the removed ids |
| event `command {command_id, app, key, action, data}` | ← HA | `turn_on`, `press`, `set_value`, `select_option`, … |
| `stewart/command/result {command_id, ok, message?}` | → HA | Answers a command; the component applies the state or raises `HomeAssistantError` |
| `stewart/sentence/register`, event `sentence`, `stewart/sentence/reply` | ↔ | PR 12 |

`protocol` is an integer, separate from the Stewart version. The component accepts exactly one protocol, so a version
skew fails loudly at `stewart/version` rather than halfway through a session.

## Pull requests, in order

Each PR is green on its own (`make check`, including `ha-check` once it exists). Within a PR, work goes in small
committable steps, with a commit message suggested at each pause. Every PR updates the CHANGELOG. A PR that touches
IPC or control bumps that protocol and its goldens. The track letters show what can run in parallel.

### Track A: component and exposure (the main line)

1. **ADRs and protocol spec.** Done. Docs only.
   - ADR 0055 and 0056 and `docs/pages/component-protocol.md` (local; `docs/` is not tracked).
   - The spec is `integrations/home-assistant/PROTOCOL.md`; goldens in `integrations/home-assistant/tests/protocol/`.
2. **Component skeleton and Python tooling.** Done.
   - Config flow, `stewart/version`, `stewart/session/subscribe` with takeover; `make ha-check` (`HA=min|latest`) in
     `make check`, `make ha-hassfest`; CI job for HA 2026.4.
   - `split.yml` mirrors to `stewart-php/hass-stewart`, releases `stewart.zip` there on tags, and runs HACS validation
     on the mirror (hacs/action reads the GitHub repository, not the checkout). The owner creates the mirror.
3. **Component detection in Stewart.** Done.
   - Client commands for version and session subscribe; PHP replays the goldens from a copy guarded against drift.
   - Broker `ComponentLink` runs on every connect, keeps the session subscription and records the state in
     `ComponentTracker` (`unchecked`, `missing`, `protocol_mismatch`, `refused`, `replaced`, `active`). A takeover
     stays `replaced` until the next reconnect.
   - `expose.instance`; `command_timeout` is a 5 s constant in `ComponentLink` until PR 8.
   - `stewart status` component row; metrics `stewart_component_info` and `stewart_component_state`; control
     protocol 25. `doctor` is not involved.
4. **Component: sensor and binary_sensor.** Done.
   - Upsert, state and remove; hub, per-app and named devices, removed when empty; unavailable on session end.
   - Lazy entities: created by upsert only; restore uses own extra data (`StoredExposure`), not HA's last state.
   - Sensor config checks unit and state_class against device_class; `timestamp`/`date` states are ISO strings.
   - `entity-remove.missing` golden is not yet in the PHP copy; PR 5 copies every entity golden it replays.
   - HA picks entity ids (2026.10 adds the area), so PHP must read `entity_id` from the upsert result.
5. **Stewart: read-only exposure.** Done.
   - `Contracts\Exposure`: `EntityExposure`, handles with value, attributes, availability and `remove()`; config VOs
     check shapes, the component checks device_class/unit/state_class. `ExposedState`, `ExposedStateChange` and
     `ExposedEntitySnapshot` live in contracts because IPC may not depend on the client.
   - `expose*()` returns a pending handle while the component is `unchecked`/`replaced`; it throws only for
     `missing`, `protocol_mismatch` and `refused`. Every handle call awaits the broker.
   - Broker `ExposureLink` replays every exposure with its latest state after `establishLink()` and pushes the
     result to the owning worker (`ExposedEntitySynced`); `not_found` re-upserts. App dispose and worker loss only
     forget the exposures; the entities stay in HA. IPC protocol 23.
   - `RecordingEntityExposure` in `stewart-php/testing`; guide page `exposed-entities.md`. The runnable skeleton
     example moves to PR 6.
6. **Real-HA smoke.** Done.
   - `make test-ha-e2e [HA=min|latest]` runs `bin/test-ha-e2e.sh` on the `.docker/ha-e2e` stack: pinned HA images
     with the component mounted, the skeleton in `var/ha-e2e`, and `smoke.py` (stdlib) as driver.
   - The driver provisions HA through onboarding and the config flow API, then checks the sensor's state and device;
     `ha-check` lints it. No Valkey; the control socket is off.
   - The skeleton's `HelloApp` exposes `changes_seen`; an `ExposureException` is logged and the app keeps running.
   - CI: `ha-e2e.yml`, path-filtered, matrix min and latest. Not part of `make check`.
7. **Availability and reconcile.** Done.
   - App failure, dispose and worker loss orphan the app's exposures: sent unavailable, kept for replay and
     reconcile; a new expose sends `available: true`. Quarantine needs nothing extra.
   - `ExposureReconciler` runs once per run: all slots ready or quarantined, 30 s grace, then on an active component.
     `keep_apps` (protocol 1, unreleased) keeps every configured app no worker reports running. `expose.prune`.
   - `exposed` column in `stewart status`, `stewart_app_exposed_entities`; control protocol 26.
8. **Commands: switch and button.** Done.
   - Component: switch and button platforms, the `command` event with the call's `context`, the wait with timeout,
     `stewart/command/result`; pending commands fail when the session ends. A button records its press before the
     answer.
   - Stewart: `exposeSwitch()`, `exposeButton()`, `watchCommands()`; the first returning subscriber accepts,
     `CommandException::rejected()` rejects, no subscriber accepts, dropped commands time out.
   - Broker `ExposureCommandRouter` refuses unknown, paused and stopped apps and rejects a dead worker's commands;
     `expose.command_timeout` (10s, at most 300s); `stewart_app_exposed_commands_total`. IPC 24, control 28.
   - Process test for worker death; the skeleton's `counting` switch in the e2e smoke test.
9. **Wave 3 platforms and runtime config.** Done.
   - Component: number, select, text, time, date and datetime; `set_value` and `select_option`. Number `min`,
     `max`, `step` are required; a text pattern is matched from the start.
   - Stewart: `expose*()` per platform with typed commands; time is `TimeOfDay`, date and datetime
     `DateTimeImmutable`, all through `CalendarStateFormat`. `ComponentCommand::readExposedCommand()` checks the
     action against the entity's platform. Every golden is copied to PHP, and the guard checks both directions.
   - `getConfig()`/`updateConfig()` on every handle via `ReconfigureExposedEntityRequest` (IPC 25): the device stays,
     the upsert carries no state, and the component drops a value the new config does not fit.
   - The skeleton's `step` number; its icon follows the counting switch in the e2e smoke test.

### Track B: registry edits (independent; can land any time)

10. **RegistryEditor.** Done.
    - `RegistryEditor::updateEntity()` with an `EntityRegistryUpdate` builder: name, icon, area, labels, aliases,
      hidden, disabled. Per-field change VOs; a missing change leaves the field. Returns the stored `RegisteredEntity`.
    - Label and alias add/remove merge on the broker against a fresh `config/entity_registry/get`; the registry list
      has no aliases, so the mirror holds `aliases: null`. `EntityAlias::entityName()` is HA's null alias.
    - Broker `RegistryEditProxy` shares `HaCallSlots`, follows `dry_run` and warns when the entity is exposed
      (`ExposureLink::findAddressByEntityId()`). IPC 26.
    - `RecordingRegistryEditor` in `stewart-php/testing`; guide section in `registry.md`.

### Track C: sentences

11. **Typed `ConversationTrigger`.** Independent, no component. A builder for HA core's `conversation` trigger, used
    with `watchTrigger()`, with a fixed reply. Guide section in `triggers.md`.
12. **Dynamic replies.** Needs 3. Implements `stewart/sentence/register` and `reply`: `watchSentences()` returns an
    `EventStream<SentenceEvent>`, and `SentenceEvent::reply()` answers within the registered timeout.

### Priority

The order to deliver: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9, with 10 and 11 slotted in wherever review capacity allows,
and 12 last. PRs 1–5 are the minimum useful release: apps can publish sensors. Through 8, it covers what most
Node-RED Companion users rely on.

## Open questions for the detailed plans

Each plan settles these with the owner, offering options and a recommendation.

- **PR 12.** How sentences behave when the owning app is paused (fixed fallback reply versus an HA error).
- **All.** What the HACS default-store submission needs (brands repository entry, icon), and who owns it.

## Out of scope

- MQTT discovery as a second backend.
- Webhooks, device triggers and device actions through the component.
- Entity platforms beyond wave 3 (light, cover, climate, fan, …).
- Editing devices, areas or labels themselves; only entity registry entries are edited.
