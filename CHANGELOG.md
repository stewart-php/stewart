# Changelog

Every package, the `ghcr.io/stewart-php/runtime` image, the Helm chart, the skeleton and the Home Assistant
integration share one version. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and from
1.0 Stewart follows [semantic versioning](https://semver.org/): only a major release breaks. Before 1.0, a minor
release could break; a release's "Upgrading" section says what to change.

## [Unreleased]

### Added

- `apps.<id>.log_level` (`STEWART_APPS__<ID>__LOG_LEVEL`) gives one app a log level of its own, louder or quieter
  than `log_level`; a reload applies it. A new Logs guide explains the log line
- `apps.<id>.trace` and `stewart run --trace=<id>` (`make run TRACE=…`, also `watch` and `debug`) log `TRACE` lines for
  one app at any log level: each event that reaches one of its subscriptions, each call of its handler, what a pause
  skipped, its schedule runs, and its service calls with the outcome and how long they took. An event received but
  never handled was stopped by an operator such as `filter()`. In JSON logs, trace records have `level_name` `TRACE`

### Changed

- Every log line names what it is about after the level: the app ID, `@shared` for Stewart's code inside a worker,
  `broker`, or `worker N` for text a worker printed outside the logger. Lines Stewart writes about an app, such as
  `App failed` or `App paused`, carry that app's tag and follow its log level, and the line no longer repeats the app
  ID in its trailing details. JSON logs keep `context.app` and add the tag as `extra.source`
- The broker's routing debug lines (`Home Assistant event routed`, `Topic published`, `MQTT message routed`,
  `Home Assistant trigger routed`) name the matched apps instead of worker numbers, and the `service_calls.dry_run`
  lines carry the tag of the app that made the call

## [1.6.0] - 2026-10-21

Edit apps and their settings without restarting: a reload restarts the workers on the current code and app settings
while the Home Assistant session stays open, and `make watch` reloads on every save.

### Added

- `stewart reload` (`make reload`), `POST /api/reload` and `SIGHUP` check the project as `stewart check` does, then
  restart every worker on the current code while the Home Assistant session stays open; a failed check leaves
  everything running. These restarts do not count toward a worker's `restarts`, and a quarantined worker comes back.
  `stewart status` shows the last reload on a `reload` row, and `stewart_reloads_total{outcome}` counts them
  ([#103](https://github.com/stewart-php/stewart/pull/103))
- A reload also applies `stewart.yaml` and the app list: added and removed apps, `apps.<id>` options, `enabled`,
  `paused` and `worker`, and `workers`. Any other changed setting keeps its value until a restart, and the `reload`
  row says `partly applied` and names it ([#104](https://github.com/stewart-php/stewart/pull/104))
- `stewart run --watch` (`make watch` in new projects) reloads once a saved change to the apps, `services.php` or
  `stewart.yaml` settles; a changed `.env` or `composer.lock` is logged as needing a restart
  ([#105](https://github.com/stewart-php/stewart/pull/105))

### Changed

- In Git mode, the image entrypoint forwards `SIGHUP` to the daemon; before, it stopped the container
  ([#103](https://github.com/stewart-php/stewart/pull/103))
- The control protocol is 30, so `stewart status` and the other control commands need the same release as the daemon
  ([#103](https://github.com/stewart-php/stewart/pull/103))
- After a reload removes an app, its exposed entities are deleted after the usual grace when `expose.prune` is on
  ([#104](https://github.com/stewart-php/stewart/pull/104))

### Upgrading

1. Run `make upgrade VERSION=1.6`, then restart Stewart (`make up`, `make deploy` or a new image). The control
   protocol is now 30: until the daemon runs 1.6, `make status` and the other control targets refuse to talk to it.
   The Home Assistant integration's protocol is unchanged.
2. The new targets reach only new projects. To use them, copy `reload` and `watch` from the
   [skeleton's Makefile](https://github.com/stewart-php/skeleton/blob/main/Makefile).
3. `docker compose kill -s HUP stewart` now reloads Stewart instead of stopping it, also in Git mode.

## [1.5.0] - 2026-10-21

Mistakes surface before they reach the server: `stewart check` builds every app, and new projects run it in CI and
before every `make deploy`.

### Added

- `stewart check` builds every enabled app's container the way its worker does, without running any app code, and
  fails naming each app that cannot be built: a missing or unknown option, a constructor argument nothing provides,
  a `Store` without `persistence.url`, an `Mqtt` without `mqtt.url`, or a `#[PeerStore]` naming no app, with the
  closest ID ([#96](https://github.com/stewart-php/stewart/pull/96))
- `stewart check` notes classes in `apps/` that implement `App` without `#[Automation]`, as `stewart run` does
  ([#96](https://github.com/stewart-php/stewart/pull/96))
- `Stewart\Contracts\Disposable` for apps that release something when they stop; apps that open nothing need no
  `dispose()` any more, and new projects, `make:app` and the examples have none
  ([#99](https://github.com/stewart-php/stewart/pull/99))
- The test harness's recorded handles send commands without building them: `pushTurnOn()` and `pushTurnOff()` on a
  switch, `pushPress()` on a button, `pushOption()` on a select, and `pushValue()` on a number, text, time, date or
  date-time; the event context defaults to `EventContext::unknown()`
  ([#100](https://github.com/stewart-php/stewart/pull/100))

### Changed

- A Git deploy rejects a commit with an app that cannot be built, because `stewart check` now builds the apps; before,
  the commit went out and that app failed on its own ([#96](https://github.com/stewart-php/stewart/pull/96))
- An app that takes a `Store` or `Mqtt` that is not configured, or a `#[PeerStore]` naming no app, fails when its
  worker builds the container, with the reason `app_build_failed`, instead of when it is constructed
  ([#96](https://github.com/stewart-php/stewart/pull/96))
- New projects' CI also runs `make check` against the settings in `.env.example`, and the Image workflow runs CI
  first, so a commit that fails it gets no image ([#97](https://github.com/stewart-php/stewart/pull/97))
- `make deploy` in new projects installs changed dependencies and runs `stewart check` in one-off containers before
  it restarts Stewart, so a deploy is down only for the restart, and a failed check leaves the running daemon up
  ([#98](https://github.com/stewart-php/stewart/pull/98))
- `App` declares only `initialize()`; `dispose()` moved to `Disposable`. An app that marks `dispose()` with
  `#[\Override]` must implement `Disposable`, or PHP refuses to load it
  ([#99](https://github.com/stewart-php/stewart/pull/99))
- An app without `worker:` is placed by a hash of its ID instead of round-robin, so adding or removing an app moves
  no other. After the upgrade, apps and their `worker` metric label move once; the spread can be less even with few
  apps, and `apps.<id>.worker` still pins one ([#101](https://github.com/stewart-php/stewart/pull/101))

### Deprecated

- A public `dispose()` on an app that does not implement `Disposable`. Stewart still calls it until 2.0, and
  `stewart check` names each such app ([#99](https://github.com/stewart-php/stewart/pull/99))

### Upgrading

1. Apps without `worker:` can move to another worker once, so the `worker` label of their metrics changes. If
   dashboards or alerts rely on it, run `make check` before upgrading, note each app's worker, and pin it with
   `apps.<id>.worker`.
2. Run `make upgrade VERSION=1.5`. Protocols are unchanged, so a 1.4 daemon, `stewart` CLI and Home Assistant
   integration work with 1.5.
3. Run `make check`. It now builds every app, so it can name a problem that used to show only when the app started,
   and a Git deploy rejects a commit until it passes.
4. Delete the empty `dispose()` from your apps; `make check` names each app that still has one. Where `dispose()`
   does something, add `implements Disposable` (`use Stewart\Contracts\Disposable;`). An app that marks `dispose()`
   with `#[\Override]` does not load until you do one or the other.
5. The new deploy and CI steps reach only new projects. To use them, copy the `deploy` target from the
   [skeleton's Makefile](https://github.com/stewart-php/skeleton/blob/main/Makefile), and `ci.yml` and `image.yml`
   from its [workflows](https://github.com/stewart-php/skeleton/tree/main/.github/workflows). CI checks the settings
   in `.env.example`, so keep it listing every setting your apps need.

## [1.4.0] - 2026-10-20

A new project reaches its first automation in fewer steps, and `stewart doctor` names what is missing.

### Added

- The Helm chart creates a Service for the admin API once `adminApi.host` is reachable from other pods, so other
  workloads reach it at `http://<release>-stewart.<namespace>.svc:8081`
  ([#88](https://github.com/stewart-php/stewart/pull/88))
- `stewart check` lists every app with its class, the worker it runs on and whether it is enabled, paused or
  disabled ([#91](https://github.com/stewart-php/stewart/pull/91))
- New projects get `make check`, `make app-pause APP=…`, `make app-resume APP=…`, `make app-reset APP=…` and
  `make worker-restart WORKER=…` ([#92](https://github.com/stewart-php/stewart/pull/92))
- `stewart make:app PorchLight` (`make app NAME=PorchLight` in a project) creates an app in `apps/` and its test in
  `tests/`, deriving the ID `porch-light` unless `--id` sets one
  ([#93](https://github.com/stewart-php/stewart/pull/93))
- `stewart doctor` (`make doctor`) also checks `.env`, the configuration, the Home Assistant connection, whether the
  token belongs to an administrator, the Home Assistant location, the Stewart integration and the store; settings
  that are not there are skipped, and `--offline` keeps only the container checks, as `make setup` does
  ([#94](https://github.com/stewart-php/stewart/pull/94))

### Changed

- New projects start with a short `HelloApp` that greets in the log and with a Home Assistant notification; the
  exposure demo moved to `ChangeCounter`, which stays off until the Stewart integration is installed
  ([#89](https://github.com/stewart-php/stewart/pull/89))
- Valkey starts only while `.env` sets `COMPOSE_PROFILES=store`; new projects have that line next to
  `STEWART_PERSISTENCE__URL`, so removing both leaves Valkey off when no app takes a `Store`
  ([#90](https://github.com/stewart-php/stewart/pull/90))
- The skeleton README starts with the Docker-only `composer create-project` command, and the install guide covers
  Windows through WSL2 ([#90](https://github.com/stewart-php/stewart/pull/90))

### Upgrading

1. Run `make upgrade VERSION=1.4`. Protocols are unchanged, so a 1.3 daemon, `stewart` CLI and Home Assistant
   integration work with 1.4.
2. The new Makefile targets reach only new projects. To use them, copy `check`, `app`, `app-pause`, `app-resume`,
   `app-reset`, `worker-restart` and the `required` line from the
   [skeleton's Makefile](https://github.com/stewart-php/skeleton/blob/main/Makefile); there, `doctor` runs with
   `$(RUN_APP)` and `setup` runs `stewart doctor --offline`.
3. To make Valkey optional, first add `COMPOSE_PROFILES=store` to `.env` next to `STEWART_PERSISTENCE__URL`. Then add
   `profiles: [store]` to the `valkey` service and `required: false` under `depends_on: valkey:` in `compose.yaml` and
   in any deploy compose file you copied. With the profile but without the `.env` line, Valkey no longer starts.
4. An existing `HelloApp` keeps working; nothing changes for it.

## [1.3.0] - 2026-10-20

Home Assistant can delete the devices of a daemon that is gone, an unknown app ID in the configuration warns
instead of stopping Stewart, and status reports the memory each process really uses.

### Added

- Home Assistant can delete the devices of a Stewart daemon that is not connected; deleting its hub removes its
  devices and entities, so a test `expose.instance` no longer lingers

### Fixed

- The Helm chart rolls the pod when `homeAssistant.token` or `persistence.url` changes, instead of keeping the
  old value until the next restart
- `host.docker.internal` reaches the host with `make up` and the deploy compose files too, not only with `make run`
- `stewart status` and the `stewart_broker_memory_bytes` and `stewart_worker_memory_bytes` metrics report each
  process's resident memory, instead of the PHP heap, which left out opcache and extensions; expect higher values
- An app ID in `stewart.yaml` or a `STEWART_APPS__*` variable that names no automation logs a warning and is ignored,
  as documented, instead of stopping the daemon; `stewart check` prints the warning and passes
- `stewart --help` lists the commands, instead of showing the help of `run`; `stewart run --help` still shows it
- The CLI reference lists only each command's own arguments and options, instead of the console's global options
  under `config:reference`
- The metrics reference lists the labels of `stewart_app_exposed_commands_total` and
  `stewart_worker_restart_due_timestamp_seconds`, instead of none
- The error reference says what to do for the errors a first run or an upgrade hits: Home Assistant connection and
  token, configuration files and tokens, control and worker protocol versions, and the store

### Upgrading

1. Run `make upgrade VERSION=1.3`. Protocols are unchanged, so a 1.2 daemon, `stewart` CLI and Home Assistant
   integration work with 1.3.
2. For `host.docker.internal` with `make up`, add `extra_hosts: ["host.docker.internal:host-gateway"]` to the
   `stewart` service in `compose.yaml`, and in any deploy compose file you copied.
3. Memory now reads higher, since it counts the whole process; raise alerts on `stewart_broker_memory_bytes` and
   `stewart_worker_memory_bytes` to match.

## [1.2.1] - 2026-10-19

A burst of state changes from Home Assistant reaches workers whole again.

### Fixed

- State changes of an entity merge only once a full `worker_state_batch` is queued for a worker, so a burst from one
  Home Assistant frame reaches handlers step by step instead of losing the changes between its first and its latest

## [1.2.0] - 2026-10-19

State changes cost less CPU on the broker and on workers, and Home Assistant traffic arrives in fewer, cheaper frames.

### Changed

- MQTT and Valkey connections turn on `TCP_NODELAY`, so a burst of small packets no longer waits on delayed ACKs
- The Home Assistant client asks for `coalesce_messages`, so a burst of events arrives in fewer WebSocket frames
- The runtime image keeps compiled PHP in `/tmp` (`opcache.file_cache`), so workers and console commands after the
  first start without compiling the framework again; code is still revalidated when files change
- A state change's `from` comes from the worker's state cache, so it is always what `getState()` returned just before
  the change
- Workers receive only the new state of each change, and the broker no longer decodes Home Assistant's `old_state`,
  so each state change costs less CPU on the broker and on every worker
- The broker encodes state changes, snapshots and history for workers with hand-written converters instead of
  reflection, and waiting on one entity no longer clears every other entity's cached routing match

### Fixed

- `stewart status`, `app:pause`, `app:resume`, `app:reset` and `worker:restart` fail at once when no daemon
  listens, instead of retrying the control socket for six seconds whatever `--timeout` says
- A Home Assistant frame with invalid UTF-8 is logged and dropped, instead of closing the connection and forcing a
  resync

## [1.1.0] - 2026-10-15

The runtime image runs the event loop on `ext-ev`, and the Helm chart keeps fewer old ReplicaSets.

### Added

- The Helm chart's `revisionHistoryLimit` sets how many old ReplicaSets the Deployment keeps; 3 by default, down
  from Kubernetes' 10

### Changed

- The runtime image includes `ext-ev`, so the event loop runs on Revolt's `EvDriver` instead of `stream_select()`;
  `stewart-php/runtime` suggests it for installs outside the image. Projects on `runtime:1` get it with their next
  image pull, without a Composer update. Set `REVOLT_DRIVER='Revolt\EventLoop\Driver\StreamSelectDriver'` to keep
  `stream_select()`

## [1.0.0] - 2026-10-13

The first stable release. From here on, `stewart-php/contracts`, the generated classes, configuration keys,
environment variables, console commands and the image entrypoint settings change only in a major release. The code is
the same as 0.11.1.

### Added

- Documentation at [stewart-php.github.io/docs](https://stewart-php.github.io/docs/), and for contributors at
  [stewart-php.github.io/docs-dev](https://stewart-php.github.io/docs-dev/)
- The runtime image is also tagged with its major, `1` and `1-dev`, rebuilt every week

### Changed

- The skeleton uses the `runtime:1` image tag, and `make upgrade` writes the major for image tags

### Upgrading

1. Run `make upgrade VERSION=1.0`. Protocols are unchanged, so a 0.11 daemon, `stewart` CLI and Home Assistant
   integration work with 1.0.
2. Change the image tag in `Dockerfile` and the compose files from `runtime:1.0` to `runtime:1` (`1-dev` in
   `compose.dev.yaml`), so the image keeps getting PHP and Alpine fixes after 1.1. Copy `bin/upgrade-stewart.sh` from
   [the skeleton](https://github.com/stewart-php/skeleton) so later upgrades do the same.

## [0.11.1] - 2026-10-13

Publishes the runtime image, Helm chart and GitHub release that 0.11.0 did not.

### Fixed

- The release scans each runtime image for its own platform, so the arm64 build no longer fails it

## [0.11.0] - 2026-10-13

Handlers can wait for states and events, apps can define their own Home Assistant actions, and `AppHarness` makes
apps easy to test. Workers can be restarted on demand, Stewart can run from environment variables alone, and many
reliability issues are fixed.

### Added

- Awaitable waits with timeouts: `waitForState()`, `EventStream::waitForNext()` and `Timers::waitFor()`
- App-defined Home Assistant actions with `EntityExposure::exposeAction()`
- `AppHarness` and `ManualScheduler` in `stewart-php/testing` for testing apps on virtual time
- `HaContext::wasCausedByStewart()` to tell Stewart's own changes apart
- `stewart worker:restart` and `POST /api/workers/{id}/restart`
- Running without a `stewart.yaml`, configured from the environment alone
- App options read from a file through `STEWART_APPS__<APP>__OPTIONS__<NAME>_FILE`
- `StateChange::withFrom()`

### Changed

- `whenChangedTo()` takes `from:` and `fromAnyState:` arguments instead of chained calls
- `stewart-php/contracts` holds only the app-facing API; internal helpers moved to `stewart-php/support`
- `stewart-php/testing` is public and covered by the backward-compatibility check
- Exceptions are created with `createForReason()`, and collections with `fromList()`; most shortcut factories are gone
- A full subscription queue drops superseded changes first, so each entity's latest change still arrives
- History queries share the service-call in-flight limit
- `off` or an empty value disables `persistence.url`, `mqtt.url` and the `listen` settings
- App IDs in `stewart.yaml` may use `_` in place of `-`
- Control and IPC protocol 29, component protocol 2 and generated code format 6

### Fixed

- Large resyncs and bursts of state changes are no longer partly lost
- Exposed entities come back right after the `stewart` integration reloads
- Commands to a paused or stopped app are refused at once instead of timing out
- Environment overrides work for hyphenated app IDs and camelCase options
- One-shot timers that come due while an app is paused run when it resumes
- Startup stops when no worker can start, and readiness reports when no worker is running
- Handlers that keep failing from timers log far less
- MQTT no longer loops on invalid topics, unacknowledged messages or rejected connections
- Release pipeline: package tags, CI gate and image scanning

### Removed

- `StateTransitionStream`, `->from()` and `->fromAnyState()`
- Injectable `StewartIdentity`
- `runAtSunrise()` and `runAtSunset()`

### Upgrading

1. Run `make upgrade VERSION=0.11`, then `make generate`. Upgrade the daemon, the `stewart` CLI and the Home Assistant
   integration together; older versions refuse to talk to 0.11.
2. Replace `->whenChangedTo('on')->from('off')` with `->whenChangedTo('on', from: 'off')`, and `->fromAnyState()`
   with `fromAnyState: true`.
3. Replace `runAtSunrise()` and `runAtSunset()` with `runAtSunEvent(SunEvent::Sunrise, …)` or `SunEvent::Sunset`.
4. Replace an injected `StewartIdentity` with `$ha->wasCausedByStewart($change)`.
5. Replace removed exception factories with `createForReason()`, `fromWire()` with `restoreFromWire()`, and removed
   collection factories such as `fromIds()` with `fromList()`.
6. Import classes that moved out of `stewart-php/contracts` from `Stewart\Support\…`.
7. Custom implementations of `Timers`, `EventStream`, `HaContext` or `EntityExposure` add the new methods.
8. An app option variable ending in `_FILE` is now read as a file; set the option in `stewart.yaml` to keep passing
   the path itself.

## [0.10.0] - 2026-10-12

A maintenance release: quieter worker shutdown, tighter package manifests and a hardened skeleton CI.

### Fixed

- Workers stopping during daemon shutdown no longer log errors: a worker in the new `Stopping` state drops sends after
  its shutdown request, and a disconnecting worker counts as closed
- Generated field summaries keep a label or description of `"0"`

### Changed

- `stewart-php/contracts` and `stewart-php/runtime` require `ext-filter`; `stewart-php/client` and
  `stewart-php/store-redis` no longer require `revolt/event-loop` directly
- `RecordingLogger` and `RecordedLog` in `stewart-php/testing` accept any log context array, as PSR-3 does
- The skeleton's workflows check out without persisted credentials and grant `packages: write` only to the image jobs;
  its `Dockerfile` sets `WORKDIR /app` in the build stage

### Upgrading

1. Run `make upgrade VERSION=0.10`. Control and IPC protocols are unchanged, but broker, workers and `stewart`
   commands should all run 0.10.
2. Update the `stewart` integration to 0.10.0 in Home Assistant.
3. Optionally take over the skeleton's `.github/workflows/*.yml` and `Dockerfile` changes.

## [0.9.0] - 2026-10-09

Apps now show up in Home Assistant as entities of their own. The new `stewart` integration, installed through HACS,
lets an app expose sensors, switches, buttons, numbers, selects and other controls, receive their commands, and edit
entity registry entries.

### Highlights

- **The `stewart` Home Assistant integration.** Installed from `stewart-php/hass-stewart` through HACS; the daemon
  detects it on every connect and `stewart status` shows it on a `component` row.
- **Exposed entities.** An injected `EntityExposure` gives apps `sensor`, `binary_sensor`, `switch`, `button`,
  `number`, `select`, `text`, `time`, `date` and `datetime` entities. `watchCommands()` streams typed commands, and
  entities turn unavailable while their app or daemon is down.
- **Registry edits.** An injected `RegistryEditor` renames, labels, hides or moves entities in Home Assistant's entity
  registry.
- **Testing.** `RecordingEntityExposure` and `RecordingRegistryEditor` in `stewart-php/testing` cover both in unit
  tests.

### Added

- The protocol between Stewart and its Home Assistant integration, `integrations/home-assistant/PROTOCOL.md`, with
  JSON goldens for every message in `integrations/home-assistant/tests/protocol/`
- The `stewart` Home Assistant integration, installed through HACS from `stewart-php/hass-stewart` or by unpacking
  `stewart.zip` from its releases; Home Assistant 2026.4 or newer. It has a config flow, `stewart/version` and
  `stewart/session/subscribe`
- The `stewart` integration creates and drives `sensor` and `binary_sensor` entities through
  `stewart/entity/upsert`, `state` and `remove`. Each daemon gets a hub device and each app a device under it, or an
  entity names its own device. Entities restore their state and attributes after a Home Assistant restart and become
  unavailable while their daemon has no session
- `stewart/entity/reconcile` removes every entity of a daemon that it no longer exposes, except those of the apps it
  names in `keep_apps`
- `make ha-check` (ruff, mypy, pytest; `HA=min` for Home Assistant 2026.4) as part of `make check`, and
  `make ha-hassfest`
- The daemon detects the `stewart` integration on every Home Assistant connect and holds its session open;
  `stewart status` shows it on a `component` row, with the reason when it is missing, speaks another protocol or was
  taken over by another daemon
- `expose.instance` setting (`default`), naming this daemon in Home Assistant; daemons sharing one Home Assistant need
  different names
- Shortly after startup, once every worker is ready or quarantined, the daemon removes its entities that no running
  app exposed; entities of apps that failed or did not start stay. `expose.prune: false` turns it off
- Metrics `stewart_component_info{version,protocol}` and `stewart_component_state{state}`
- `stewart status` shows how many entities each app exposes in an `exposed` column, also exported as
  `stewart_app_exposed_entities`
- Apps expose their own `sensor` and `binary_sensor` entities through an injected `EntityExposure`:
  `exposeSensor()` and `exposeBinarySensor()` return handles that set the value, attributes and availability and
  read back the entity ID and the state Home Assistant restored. Exposed entities are sent again after every
  reconnect; without a compatible integration, `expose*()` throws `ExposureException`
- `Stewart\Testing\Exposure\RecordingEntityExposure` for unit tests of apps that expose entities
- `Stewart\Testing\Registry\RecordingRegistryEditor` records registry edits and applies them to an `InMemoryRegistry`
- The skeleton's `HelloApp` exposes `sensor.stewart_hello_changes_seen`; without the integration it logs why and keeps
  running
- `make test-ha-e2e` (`HA=min|latest`) boots a real Home Assistant with the integration and checks that the skeleton's
  sensor appears, updates and turns unavailable when the daemon stops; CI runs it on both versions
- The `stewart` integration creates `switch` and `button` entities. Their service calls send a `command` event, with
  the call's context, on the session and wait for `stewart/command/result`; a refusal, a timeout or the session
  ending fails the call
- Apps expose switches and buttons with `exposeSwitch()` and `exposeButton()`. `watchCommands()` streams
  `SwitchCommand` and `ButtonPress`; a handler that returns accepts the command, `CommandException::rejected()`
  fails it with its message. Commands for paused or stopped apps fail at once, and a worker dying mid-command fails
  it without waiting for the timeout
- `expose.command_timeout` setting (`10s`, at most `300s`): how long Home Assistant waits for an app's answer
- Metric `stewart_app_exposed_commands_total{outcome}` (`accepted`, `rejected`, `refused`, `lost`)
- `RecordingExposedSwitch::pushCommand()` and `RecordingExposedButton::pushCommand()` run an app's command handlers in
  unit tests
- The skeleton's `HelloApp` exposes `switch.stewart_hello_counting`, which pauses its change counter, and
  `number.stewart_hello_step`, how much each change adds, whose icon shows the pause through `updateConfig()`; the
  e2e smoke test sets the step and turns counting off and on
- The `stewart` integration creates `number`, `select`, `text`, `time`, `date` and `datetime` entities; their
  `set_value` and `select_option` service calls send `set_value` and `select_option` commands
- Apps expose numbers, selects, texts, times, dates and datetimes with `exposeNumber()`, `exposeSelect()`,
  `exposeText()`, `exposeTime()`, `exposeDate()` and `exposeDateTime()`. Times are `TimeOfDay`, dates and datetimes
  `DateTimeImmutable`. `watchCommands()` streams a typed command per platform; an accepted command sets the handle's
  value. The `RecordingExposed*` handles push them in unit tests
- Every exposed handle has `getConfig()` and `updateConfig()`, which changes the entity's name, icon, options or
  limits in place and keeps its device; Home Assistant drops a value that no longer fits to unknown
- `RegisteredEntity` carries the entity's `icon`, and `aliases` as `EntityAlias` where Home Assistant sends them; the
  mirrored registry has none, so it holds `null` there
- Apps edit Home Assistant entity registry entries through an injected `RegistryEditor`: `updateEntity()` takes an
  `EntityRegistryUpdate` that renames, sets the icon, area, labels and aliases, hides or disables an entity, and
  returns the updated `RegisteredEntity`. Added and removed labels and aliases merge with the entry Home Assistant
  holds. Edits share the service-call budget and follow `service_calls.dry_run`; failures throw
  `RegistryEditException`. Editing an entity that a Stewart app exposes logs a warning naming the app and key

### Changed

- Entities of an app that fails, stops or loses its worker turn unavailable until the app exposes them again, instead
  of keeping their last state as if live
- The control protocol is version 28: snapshots carry `component` and each app's `exposed_entities` and
  `exposed_commands`
- IPC protocol 26; broker and workers must run the same version

### Upgrading

1. Run `make upgrade VERSION=0.9`. Broker, workers and `stewart` commands must all run 0.9; `stewart status` against
   a 0.8 daemon is refused (control protocol 28).
2. Install the `stewart` integration 0.9.0 in Home Assistant 2026.4 or newer before an app calls `expose*()`; without
   it, `expose*()` throws `ExposureException`. Apps that expose nothing need no integration.
3. Daemons sharing one Home Assistant set a different `expose.instance` each.

## [0.8.0] - 2026-10-08

A git deployment now follows its branch. The daemon fetches the ref every minute, installs a new commit beside the
running one, checks it with `stewart check`, and only then restarts itself on it, without a pod restart. A commit that
fails the check is never deployed.

### Highlights

- **Deploys without restarts.** In git mode (`code.mode: git`, `deploy/compose.git.yaml`) each push reaches the daemon
  within `deploy.git.poll`; dependencies are copied from the running release when `composer.lock` is unchanged.
- **A bad commit stays out.** A commit whose configuration or `apps/` files do not load is rejected, logged, shown by
  `stewart status` and counted in `stewart_deploy_failures_total`; the running release keeps going.
- **`stewart check`.** The same validation runs from the command line, for CI or before a deploy.

### Added

- `stewart check` fails when the configuration does not load or a file in `apps/` cannot be loaded
- `deploy.git.poll` (`off` by default) and `deploy.git.prepare_timeout` (`10m`) settings
- The runtime image keeps git releases in `/app/releases/<sha>` with `/app/current` pointing at the running one, keeps
  the three newest, and records rejected commits so they are not checked again
- `stewart-entrypoint release-prepare`, `release-check` and `release-stage`, which the daemon runs while it polls
- `stewart run` exits with 75 when it stops to start again on a staged release; the entrypoint restarts it
- `stewart status` shows the deployed commit, the last poll and the last rejected commit with its reason
- Metrics `stewart_deploy_info{commit}` and `stewart_deploy_failures_total`
- Chart value `code.git.poll` (`1m`)

### Changed

- The chart's git mode fetches in the daemon container; the `checkout` init container is gone
- `code.generateOnStart` applies in git mode through the daemon container
- In git mode the entrypoint keeps PHP as its child and forwards SIGTERM and SIGINT to it
- Restarting a git deployment checks a new head before running it, instead of running it unchecked
- `STEWART_BOOT_*` variables stay set in git mode, so the daemon can run the entrypoint's release commands
- The control protocol is version 24: snapshots carry `deploy`
- `stewart-php/runtime` requires `amphp/process` directly

### Upgrading

1. Run `make upgrade VERSION=0.8`. Broker, workers and `stewart` commands must all run 0.8; `stewart status` against
   a 0.7 daemon is refused (control protocol 24).
2. A git deployment whose `composer.lock` still holds runtime 0.7 sets `code.git.poll: off` (or drops
   `STEWART_DEPLOY__GIT__POLL` from `compose.git.yaml`) until it upgrades; 0.7 stops on the unknown variable.
3. A compose git volume from 0.7 is converted on the first start: the old checkout is removed and the release layout
   created, with one full dependency install.
4. Code calling `BrokerLifecycle::run()` directly gets a `BrokerStopOutcome` back instead of nothing.

## [0.7.0] - 2026-10-07

Automations need less glue. State streams can start from the current state and react to numeric thresholds, a
schedule can follow an `input_datetime`, and states and clocks answer "how long?" and "is it night?" directly.

### Highlights

- **Numeric thresholds.** `watchStateChanges('sensor.living_room_temperature')->whenAbove(25.0, for:
  Duration::minutes(5), hysteresis: 0.5)` fires once per crossing; `whenBelow()` is its mirror, and `attribute:`
  reads a climate entity's `current_temperature` instead of the state.
- **Starting from now.** `startWithCurrentState()` emits each matching entity's current state before live changes,
  marked `StateChange::isInitial()`, so a handler no longer reads the state once by hand at startup.
- **Entity-driven schedules.** `$scheduler->runAtEntityTime('input_datetime.wake_up', fn () => …)` runs at the time
  the helper or a timestamp sensor holds and follows its changes, without a Home Assistant trigger subscription.
- **Time helpers.** `$clock->isWithin('22:00', '06:00')` checks a daily window across midnight, and
  `$state->hasHeldFor(Duration::minutes(10), $clock)` checks how long a state has kept its value.

### Added

- `Clock::isWithin()` tells whether local time is in a daily window; the start is included, the end is not, and an
  equal start and end is refused (`ScheduleError::TimeWindowEmpty`)
- `EntityState::hasHeldFor()` and `getHeldDuration()` measure the time since `lastChangedAt`
- `whenAbove()` and `whenBelow()` on state streams fire once per numeric threshold crossing, with `for:`, `attribute:`
  and `hysteresis:`
  - A crossing counts only from a known number; a first reading or one after `unavailable` primes without firing
  - A negative hysteresis or a non-finite threshold is refused (`StateError::ThresholdHysteresisNegative`,
    `ThresholdNotFinite`)
- `startWithCurrentState()` on state streams emits the matching entities' current states first, marked
  `StateChange::isInitial()` (`StateChangeOrigin::Initial`); only the operators after it see them
- `Scheduler::runAtEntityTime()` runs at the time an `input_datetime` or timestamp sensor holds
  - A date and time runs once, a time alone every day; a date only, a past moment or `unavailable` waits for the next
    change
  - It re-arms on every change, re-reads the entity when a paused app resumes and right before each run
  - Other domains are refused (`ScheduleError::EntityTimeDomainUnsupported`)
- `RecordingHaContext::seedState()` takes a `changedAt:` instant

### Changed

- `RecordingHaContext::seedState()` and `pushState()` stamp `lastChangedAt` and `lastUpdatedAt`; `pushState()` moves
  `lastChangedAt` only when the value changes
- `StateChanges` and `StateTransitions` take a `CurrentStateReader`

### Upgrading

1. Run `make upgrade VERSION=0.7`. Broker, workers and `stewart` commands must all run 0.7.
2. Code that constructs `StateChanges` or `StateTransitions` directly now passes a `CurrentStateReader`.
3. Custom `Clock` and `Scheduler` implementations add `isWithin()` and `runAtEntityTime()`.
4. Tests comparing whole `EntityState` objects from `RecordingHaContext` with `assertEquals` now see the stamped
   `lastChangedAt` and `lastUpdatedAt`.

## [0.6.0] - 2026-10-06

Stewart can now be operated from outside. Apps can be paused and resumed without a restart, from the CLI or over
HTTP, and the daemon answers Kubernetes probes and Prometheus scrapes on its own HTTP ports.

### Highlights

- **Pausing apps.** `stewart app:pause porch` stops an app's events and schedule runs without unloading it;
  `app:resume` undoes it and `app:reset` hands the decision back to `apps.<id>.paused` in `stewart.yaml`. With a
  persistence store the choice survives restarts.
- **HTTP probes.** `http.listen` answers `/healthz` and `/readyz` in-process, so Kubernetes probes no longer start PHP.
  The chart uses them by default.
- **HTTP admin API.** `http.admin.listen` with a bearer token lists apps, pauses, resumes and resets them, and
  serves `/metrics`.
- **Prometheus metrics.** `/metrics` exposes `stewart_*` metrics for apps, workers, the Home Assistant connection,
  routing, service calls and the store; the chart can create a `PodMonitor`.

### Added

- `apps.<id>.paused` starts an app loaded but paused
- `stewart app:pause`, `app:resume` and `app:reset` change an app's pause at run time
  - A paused app keeps its subscriptions and schedules but receives no events or runs; they count as `suppressed`
  - Pauses and resumes are stored in the persistence store and restored on start; `app:reset` removes the stored
    override so config decides again, and also clears overrides of apps no longer loaded
  - Unknown or disabled apps are refused; a change that could not be stored is applied and reported as a warning
- `stewart status` shows `(paused by <source> <since> ago)` and a `suppressed` column
- `http.listen` (default `off`) serves `/healthz` and `/readyz`, with the same verdicts as `stewart status --probe`
- `http.admin.listen` (default `off`) and `http.admin.token` start the HTTP admin API; the daemon refuses to start
  without a token
  - `GET /api/apps`, `GET /api/apps/<id>`
  - `POST /api/apps/<id>/pause`, `/resume` and `/reset`, recorded with the `http` source
  - `GET /metrics` in the Prometheus text format
- Worker restart and quarantine totals over the daemon's lifetime, in `stewart status` and as
  `stewart_worker_restarts_total` and `stewart_worker_quarantines_total`
- Chart
  - `probes.mode` (`http` by default, `exec` for the previous `stewart status --probe`), `probes.host`, `probes.port`
  - `adminApi.*` enables the admin API with a generated token kept in the chart secret
  - `adminApi.podMonitor.*` creates a Prometheus Operator `PodMonitor`; it refuses a loopback `adminApi.host`

### Changed

- `AppId` rejects ids longer than 100 characters (`IdentifierError::AppIdTooLong`)
- Chart probes call the HTTP port instead of running `stewart status` in the pod
- IPC protocol 22 and control protocol 23; broker, workers and the CLI must run the same version

### Upgrading

1. Run `make upgrade VERSION=0.6`. Broker, workers and `stewart` commands must all run 0.6.
2. Rename any app whose id is longer than 100 characters.
3. On Kubernetes, the chart now probes port 8080 (`probes.port`); set `probes.mode: exec` to keep exec probes.

## [0.5.0] - 2026-10-06

Apps now know where things are and who did what. They can schedule around the sun, react to any Home Assistant
trigger, tell their own changes apart from a person's, fire events, and target entities by area, floor or label.

### Highlights

- **Sun-relative scheduling.** `$scheduler->runAtSunset(fn () => …, SunOffset::before(Duration::minutes(30)))`.
  The new `stewart-php/sun` package computes sunrise, sunset, twilights, golden hour and sun position locally, and
  its times match Home Assistant's sun integration. Apps can inject `SunCalendar`, which uses Home Assistant's
  location.
- **Home Assistant triggers.** `HaContext::watchTrigger()` streams any trigger platform (time, time pattern,
  template, zone, calendar, device and more), built with `HaTrigger::atTime()`, `whenTemplateTrue()` or a raw config.
- **Who changed it.** `callService()` returns the call's `EventContext`, and `StewartIdentity::wasCausedByStewart()`
  tells Stewart's own changes from a person's. An app can then skip its own echoes or tell a manual override from
  an automation.
- **Firing events.** `HaContext::fireEvent()` puts custom events on Home Assistant's bus.
- **Areas, floors and labels.** `HaContext::getRegistry()` reads areas, floors, labels and devices, and `EntityFilter`
  selects entities through them: `watchStateChanges(EntityFilter::inArea('kitchen')->withDomain('light'))`.
  `ServiceTarget` targets them too, and PhpStorm completes their ids after `stewart generate`.

### Added

- `stewart-php/sun`: sunrise, sunset, solar noon, twilights, golden and blue hour, and sun position, computed locally
  with the NOAA equations and Astral's refraction, so times agree with Home Assistant's sun integration
  - `SunCalendar` contract: `findNextEvent()` with an optional `SunOffset`, `getDayOn()`, `getPositionAt()`,
    `getCurrentPosition()`, `isSunUp()`
  - `SunEvent`, `SunDay`, `GeoLocation`; `SunOffset::applyTo()`
  - `SunPosition` with `isHigherThan()` and `isWithinAzimuth()`, a sector that may wrap past north
  - Apps get a `SunCalendar` by type, located at Home Assistant's latitude, longitude and elevation as read at
    startup; without a location every query throws `SunException` (`LocationUnknown`)
- `Scheduler::runAtSunrise()`, `runAtSunset()` and `runAtSunEvent()` with an optional `SunOffset`: a recurring
  `ScheduledTask` with `getNextRunAt()` and missed-run counts; without a location they throw `ScheduleException`
  (`SunLocationUnknown`)
- `HaContext::watchTrigger()` streams Home Assistant triggers (`subscribe_trigger`) as `TriggerEvent`s: sun, time,
  time pattern, template, zone, calendar, device and any other trigger platform
  - `HaTrigger` builders: `onSunrise()`, `onSunset()` with a `SunOffset`, `atTime()`, `onTimePattern()`,
    `whenTemplateTrue()`, `onZoneTransition()`; `HaTrigger::fromArray()` takes any trigger config
  - An `HaTriggerCollection` or a list of configs fires on any of them; `variables` feed template triggers
  - Apps watching the same trigger share one Home Assistant subscription, re-issued after every reconnect
  - A trigger Home Assistant rejects is logged and its subscription ends (`isActive()` is false)
- `RecordingHaContext::pushTrigger()` and `listWatchedTriggers()`
- `Duration::formatAsClock()`
- `callService()` on `HaContext`, `Entity` and generated classes returns the call's `EventContext`;
  `ServiceResponse::$context` carries it for `callServiceForResponse()`
  - `StateChange::wasCausedBy($context)` and `EntityState::wasLastChangedBy($context)` match the call or anything it
    started (its child contexts)
  - `StewartIdentity`, injectable in apps, answers `wasCausedByStewart($change)` from the token's Home Assistant user;
    it also recognises the first change of a call, which arrives before `callService()` returns. It needs a Home
    Assistant user for Stewart alone: with a token from a person's account, that person's changes count as Stewart's
  - Under `service_calls.dry_run` the context id starts with `dry-run:`
- `RecordingHaContext` gives each call a context for `RecordingHaContext::STEWART_USER_ID`, records it on
  `RecordedServiceCall::$context`, and `pushState()` takes a causing context
- `HaContext::fireEvent()` fires an event on Home Assistant's bus and returns its `EventContext`
  - Event data must be keyed and transportable; nulls are kept; bad input throws `EventFireException` before sending
  - Fired events share the `service_calls` in-flight limits, per-app call counts and `dry_run` with service calls
  - Needs an administrator token, like the rest of Stewart
  - `StewartIdentity::wasCausedByStewart()` also takes an `HaEvent`, so watchers can skip Stewart's own fired events
- `RecordingHaContext::fireEvent()` records to `firedEvents`, echoes the event to `watchEvents()`, and
  `stubEventFireFailure()` makes it throw
- `HaContext::getRegistry()` reads Home Assistant's areas, floors, labels, devices and registered entities
  - `findArea()`, `findAreaByName()` (name or alias), `listAreasOnFloor()`, `listDevicesInArea()`,
    `findEntityPlacement()` and the same lookups for floors, labels, devices and entities
  - Typed `AreaId`, `FloorId`, `LabelId` and `DeviceId`; every lookup also takes a plain string
  - The broker fetches the registries on every connect and again a second after a `*_registry_updated` event
- `EntityFilter` selects entities by area, floor, label, device, domain or id pattern for `listStates()` and
  `watchStateChanges()`: `EntityFilter::inArea('kitchen')->withDomain('light')->withLabel('night')`
  - Values within one condition are alternatives; all conditions must match
  - Area, floor and labels resolve like Home Assistant targets: an entity's own area overrides its device's, labels
    come from the entity, its device and its area, and hidden or config/diagnostic entities match only by domain or id
  - A running watch picks up registry changes with the next state change
- `ServiceTarget::forAreas()`, `forFloors()`, `forLabels()` and `forDevices()` take the typed ids
- `RecordingHaContext::$registry` (`InMemoryRegistry`) seeds areas, floors, labels, devices and entities for tests
- `stewart generate` stores areas, floors and labels in the snapshot and completes their ids in PhpStorm for
  `EntityFilter`, `Registry` lookups, `ServiceTarget` and the id constructors

### Changed

- A subscription the broker refuses is cancelled in the worker, not just logged
- Refused-call warnings and reasons speak of calls to Home Assistant, covering service calls and fired events
- `RecordingHaContext` numbers contexts across service calls and fired events
- IPC protocol 21; broker and workers must run the same version
- `HaContext` gains `watchTrigger()`, `fireEvent()` and `getRegistry()`, and `callService()` returns `EventContext`
  instead of `void`; a custom implementation must follow
- Builder triggers use the `trigger:` key, which needs Home Assistant 2024.10 or newer
- Generated code format 5; regenerate after upgrading

### Upgrading

1. Run `make upgrade VERSION=0.5`. Broker and workers must both run 0.5 (IPC protocol 21).
2. Run `make generate`.

## [0.4.0] - 2026-10-04

### Added

- `whenChangedTo()->from(...)` and `->fromAnyState()` choose which previous states a transition may come from
- `getHistory(HistoryQuery)` on `HaContext`, `Entity` and generated entity classes reads one entity's recorded
  states from Home Assistant: `HistoryQuery::lastFor(Duration::minutes(30))` or `HistoryQuery::between($from, $until)`
  - `withAttributes()` includes attributes; `withAttributeChanges()` also includes attribute-only changes
  - `EntityStateHistory` answers `hasBeenIn()`, `getDurationIn()`, `countChanges()` and `getLastChangeTo()`
  - Failures throw `HistoryException`, with `HistoryError::RecorderUnavailable` when Home Assistant has no history
- `RecordingHaContext` answers history from seeded and pushed states; `seedHistoricalState()` and
  `stubHistoryFailure()` set it up, `historyQueries` records every query

### Changed

- Requires PHP 8.5
- Requires `amphp/amp` 3.1.1 and excludes older `amphp/cache`, `amphp/process` and `daverandom/libdns` releases that
  raise deprecations on PHP 8.5
- Small test adjustment for PHP 8.5
- CI now runs all newest and lowest dependency test sequentially (Newest and lowest deps still runs parallel)
- Generated code format 3; regenerate after upgrading

### Upgrading

- Add `->fromAnyState()` after any `whenChangedTo()` that must also fire when an entity comes back from
  `unavailable` or `unknown`.
- Run `make generate`.

## [0.3.0] - 2026-10-03

### Added

- `$entities->light->getEntity('light.hall')` returns the typed entity; it also takes an ID string from app options
- `stewart generate` writes `.phpstorm.meta.php` so PhpStorm completes entity IDs

### Changed

- Generated entity classes implement `TypedEntity` and throw `IdentifierError::EntityNotGenerated` for IDs outside
  their generated domain
- Generated code format 2; regenerate after upgrading

### Removed

- Per-entity properties such as `$entities->light->hall`
- The `codegen.rename` setting

### Upgrading

1. Remove `codegen.rename` from `stewart.yaml` and any `STEWART_CODEGEN__RENAME` from `.env`; both are now refused.
2. Run `make generate`.
3. Replace `$entities-><domain>-><entity>` with `$entities-><domain>->getEntity('<entity id>')`.

## [0.2.1] - 2026-10-03

The first published release of the 0.2 line; see 0.2.0 for what it adds and how to upgrade.

### Fixed

- The skeleton requires the 0.2 packages and the `runtime:0.2` image

## [0.2.0] - 2026-10-03

### Added

- New `stewart-php/mqtt` package with MQTT 3.1.1 support: apps take `Mqtt` to publish/subscribe configured under 
  `mqtt` in `stewart.yaml`.
  Supported MQTT features:
  - QoS 0 and 1
  - Retain,
  - TLS,
  - Last will
  - Reconnect with resubscribe.
- New makefile targets (`demo`, `demo-create`, `demo-sh`, `demo-clean`) for easier local testing/development
  against real instances
- `make upgrade VERSION=X.Y` in the skeleton moves a project to another release line

### Upgrading

To make life easier in long term, I added an upgrade script, that script needs to be added manually, later on upgrades
can be made by executing only one make target.

1. Fetch the upgrade script and keep it out of the production image:

   ```bash
   mkdir -p bin
   curl -fsSLo bin/upgrade-stewart.sh \
       https://raw.githubusercontent.com/stewart-php/skeleton/v0.2.0/bin/upgrade-stewart.sh
   echo bin >> .dockerignore
   ```

2. Add the target to your `Makefile`, next to `update`; the recipe lines start with a tab:

   ```makefile
   .PHONY: upgrade
   upgrade: ## Move to another Stewart release line, then pull its images and install it [VERSION=0.2]
   	$(RUN) sh bin/upgrade-stewart.sh $(VERSION)
   	$(DEV) pull
   	$(RUN) composer update 'stewart-php/*' --with-all-dependencies --no-interaction
   ```

3. To use MQTT, add `"stewart-php/mqtt": "^0.2"` to `require` in `composer.json`.

4. Upgrade. This points every `stewart-php/*` requirement and runtime image tag at 0.2, pulls the images and installs:

   ```bash
   make upgrade VERSION=0.2
   ```

5. If you added MQTT, set `STEWART_MQTT__URL` in `.env`, such as `mqtt://user:password@homeassistant.local:1883`, and
   check the `mqtt` section with `make config`. Without the URL nothing changes.

6. Check it with `make doctor` and `make run`. On the server, commit and push, then run `make deploy`.

## [0.1.0] - 2026-10-03

First release.

### Added

- First dev-only version release
