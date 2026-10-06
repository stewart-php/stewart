# Changelog

Every package, the `ghcr.io/stewart-php/runtime` image, the Helm chart and the skeleton share one version. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). On 0.x, a minor release may break; its "Upgrading"
section says what to change.

## [Unreleased]

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
- `RecordingHaContext::fireEvent()` records to `firedEvents`, echoes the event to `watchEvents()`, and
  `stubEventFireFailure()` makes it throw

### Changed

- A subscription the broker refuses is cancelled in the worker, not just logged
- Refused-call warnings and reasons speak of calls to Home Assistant, covering service calls and fired events
- `RecordingHaContext` numbers contexts across service calls and fired events
- IPC protocol 20; broker and workers must run the same version
- Builder triggers use the `trigger:` key, which needs Home Assistant 2024.10 or newer
- Generated code format 4; regenerate after upgrading

### Upgrading

- Run `make generate`.

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
