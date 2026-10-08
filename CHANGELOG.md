# Changelog

Every package, the `ghcr.io/stewart-php/runtime` image, the Helm chart and the skeleton share one version. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). On 0.x, a minor release may break; its "Upgrading"
section says what to change.

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
