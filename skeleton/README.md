# My Stewart automations

Home Assistant automations in PHP, built on [Stewart](https://github.com/stewart-php/stewart). The
[documentation](https://stewart-php.github.io/docs/) covers writing and testing apps, recipes and deploying in full;
this README is the short version.

You need Docker with Compose and a long-lived access token from Home Assistant (Profile → Security). PHP and Composer
run in the container; nothing else is installed on your machine. `vendor/` lives in this directory, so your IDE sees
the whole framework source.

## Start

```bash
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD:/work" -w /work \
  ghcr.io/stewart-php/runtime:1 composer create-project stewart-php/skeleton my-home
cd my-home
make setup        # pull the images, create .env with a control token, install, verify
```

With Composer on your machine, `composer create-project stewart-php/skeleton my-home` does the same; "Use this
template" on GitHub works too. On Windows, run all of this inside WSL2.

Fill in `STEWART_HOME_ASSISTANT__URL` and `STEWART_HOME_ASSISTANT__TOKEN` in `.env`, then:

```bash
make generate     # typed entity and action classes from your Home Assistant, into generated/
make doctor       # the connection to Home Assistant, the token's rights and the store
make check        # the configuration loads and every app can be built; lists the apps and their workers
make run          # run in the foreground; Ctrl-C stops it
make watch        # the same, and every saved change reloads the apps
make status       # from another terminal; APP=hello for one app, WATCH=1 to keep it updated
```

`make help` lists every target.

## Writing an app

An app is a class in `apps/` that implements `Stewart\Contracts\App` and carries `#[Automation(id: '…')]`;
`apps/HelloApp.php` is one. Constructor parameters are injected by type (`HaContext`, a PSR logger, `Scheduler`,
`Store`, `Clock`, your own services from `services.php`) or filled from the app's `options` in `stewart.yaml`.

`HelloApp` greets in the log and with a notification in Home Assistant, then logs every change of `sun.sun`.
`apps/ChangeCounter.php` shows what an app can expose: it counts those changes in
`sensor.stewart_change_counter_changes_seen` while `switch.stewart_change_counter_counting` is on, and each change
adds `number.stewart_change_counter_step`. Exposed entities need the `stewart` Home Assistant integration, so it is
`enabled: false` in `stewart.yaml` until you install that.

### Testing an app

`tests/HelloAppTest.php` and `tests/ChangeCounterTest.php` show the pattern: `AppHarness` from `stewart-php/testing`
builds the app with its options and fakes, the test pushes states and commands, and `advanceTime()` runs timers,
schedules and waits without waiting.
Run `make test`.

### Generated classes

`generated/` is committed: builds and deployments never need Home Assistant to produce it. Run `make generate` after
adding devices and commit the result; the daemon logs a warning when it no longer matches Home Assistant. The classes
name your entities, so keep this repository private if that matters to you.

Entities are looked up by their ID, so a search for `light.hall` finds every use. An ID from `options` works the same:

```php
public function __construct(
    private readonly Entities $entities,
    private readonly string $hallLight,
) {}

public function initialize(): void
{
    $this->entities->light->getEntity($this->hallLight)->turnOn();
}
```

An ID missing from the last `make generate` throws `IdentifierError::EntityNotGenerated`.

### Developing next to a running production daemon

Both would run every automation against the same Home Assistant. While developing, run only the app you are working
on, and keep service calls from reaching Home Assistant:

```bash
make run ONLY=hello            # only these apps, even if disabled in stewart.yaml
make run ONLY=hello DRY_RUN=1  # action calls are logged and answered as succeeded, never sent
make run TRACE=hello           # log what reaches the app and what it does with it
```

## Configuration

`stewart.yaml` holds what differs from the defaults; `make config-reference` lists every setting and `make config`
shows what is in effect. Every setting can also come from the environment: `STEWART_`, then the keys uppercased and
joined by a double underscore (`apps.hello.options.watch` → `STEWART_APPS__HELLO__OPTIONS__WATCH`).

- Secrets belong in `.env` or in files: `STEWART_HOME_ASSISTANT__TOKEN_FILE=/run/secrets/ha` reads the token from a
  file, which fits Docker and Kubernetes secrets. App options take the same suffix:
  `STEWART_APPS__WEATHER__OPTIONS__API_KEY_FILE=/run/secrets/weather` fills the `apiKey` option.
- `stewart.yaml` is optional: without it, the environment alone configures Stewart.
- `STEWART_BOOT_*` variables are read by the container entrypoint, not by Stewart; they are listed below.

## Deploying

Pick one. Each runs `ghcr.io/stewart-php/runtime`, an image that holds PHP and an entrypoint but not your code; the
framework version always comes from your `composer.lock`.

### A checkout on the server

```bash
git clone <this repository> my-home && cd my-home
cp .env.example .env    # fill it in
make up                 # installs dependencies on start, then runs
make deploy             # later: git pull, install, stewart check, then restart
```

### An image built by CI

`.github/workflows/image.yml` builds this repository into `ghcr.io/<owner>/<repository>` for amd64 and arm64 on every
push to `main` and every `v*` tag, once CI passes for that commit. Copy `deploy/compose.image.yaml` and a filled-in `.env` to the server, set the
image, and `docker compose -f compose.image.yaml up -d`. Kubernetes runs the same image (below).

### A clone that follows a branch

Copy `deploy/compose.git.yaml` and a filled-in `.env` to the server, set the repository URL, and
`docker compose -f compose.git.yaml up -d`. A private repository needs a read-only token (see the comments in the
file).

Every minute (`deploy.git.poll`) the daemon fetches the ref. A new commit is installed into its own directory beside
the running one, dependencies copied when `composer.lock` is unchanged, and must pass `stewart check`, which builds
every enabled app; then the daemon stops gracefully and starts again on it. A commit that fails the check is logged,
shown by `stewart status`, and never tried again, so the running commit stays until a fixed one is pushed. A restart
applies the same check.

| Entrypoint variable | Meaning |
|---|---|
| `STEWART_BOOT_GIT_URL` | Fetch this repository into a release directory under `/app` before starting |
| `STEWART_BOOT_GIT_REF` | Branch, tag or commit; `main` by default |
| `STEWART_BOOT_GIT_TOKEN_FILE` | File holding an HTTPS token for a private repository |
| `STEWART_BOOT_GIT_SSH_KEY_FILE` | File holding an SSH deploy key, for `git@…` URLs |
| `STEWART_BOOT_COMPOSER` | `auto` installs when `composer.lock` changed, `always`, or `never` |
| `STEWART_BOOT_GENERATE` | `1` regenerates the classes from Home Assistant before starting; a failure keeps the committed ones |

### Kubernetes

```bash
helm install home oci://ghcr.io/stewart-php/charts/stewart \
  --set code.image.repository=ghcr.io/<owner>/<repository> \
  --set homeAssistant.url=ws://homeassistant.local:8123/api/websocket \
  --set homeAssistant.existingSecret=stewart-ha
```

The chart runs one replica (two would both run every automation), probes the daemon with `stewart status`, and can
run Valkey for you. Its README lists every value, including `code.mode=git`, which follows a branch the same way.

## Upgrading

```bash
make update               # composer update within the current release line; commit composer.lock
make upgrade VERSION=2.0  # move to another major: requirements, image tags, pull, install
```

Stewart follows semantic versioning: `make update` brings minor releases and fixes, and only a new major may break.
Read its "Upgrading" notes in the [changelog](https://github.com/stewart-php/stewart/blob/main/CHANGELOG.md) before
running `make upgrade`. The image tag in `Dockerfile` and the compose files is the major (`runtime:1`), which is
rebuilt every week with PHP and Alpine fixes; Dependabot proposes Stewart releases.

## License

The skeleton this project started from is MIT-0, so none of it needs attribution. `composer.json` declares the project
`proprietary`; change it if you publish your automations under a license.
