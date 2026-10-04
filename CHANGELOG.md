# Changelog

Every package, the `ghcr.io/stewart-php/runtime` image, the Helm chart and the skeleton share one version. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). On 0.x, a minor release may break; its "Upgrading"
section says what to change.

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
