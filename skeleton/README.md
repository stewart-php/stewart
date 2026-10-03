# My Stewart automations

Home Assistant automations in PHP, built on [Stewart](https://github.com/stewart-php/stewart).

You need Docker with Compose and a long-lived access token from Home Assistant (Profile → Security). PHP and Composer
run in the container; nothing else is installed on your machine. `vendor/` lives in this directory, so your IDE sees
the whole framework source.

## Start

```bash
composer create-project stewart-php/skeleton my-home   # or "Use this template" on GitHub, then clone
cd my-home
make setup        # pull the images, create .env with a control token, install, verify
```

Fill in `STEWART_HOME_ASSISTANT__URL` and `STEWART_HOME_ASSISTANT__TOKEN` in `.env`, then:

```bash
make generate     # typed entity and service classes from your Home Assistant, into generated/
make run          # run in the foreground; Ctrl-C stops it
make status       # from another terminal
```

`make help` lists every target.

## Writing an app

An app is a class in `apps/` that implements `Stewart\Contracts\App` and carries `#[Automation(id: '…')]`;
`apps/HelloApp.php` is one. Constructor parameters are injected by type (`HaContext`, a PSR logger, `Scheduler`,
`Store`, `Clock`, your own services from `services.php`) or filled from the app's `options` in `stewart.yaml`.

Import generated classes with `use App\Generated\…;`. Inside `namespace App`, a qualified `App\Generated\X` would
resolve against the imported `Stewart\Contracts\App` interface instead.

### Generated classes

`generated/` is committed: builds and deployments never need Home Assistant to produce it. Run `make generate` after
adding devices and commit the result; the daemon logs a warning when it no longer matches Home Assistant. The classes
name your entities, so keep this repository private if that matters to you.

### Developing next to a running production daemon

Both would run every automation against the same Home Assistant. While developing, run only the app you are working
on, and keep service calls from reaching Home Assistant:

```bash
make run ONLY=hello            # only these apps, even if disabled in stewart.yaml
make run ONLY=hello DRY_RUN=1  # service calls are logged and answered as succeeded, never sent
```

## Configuration

`stewart.yaml` holds what differs from the defaults; `make config-reference` lists every setting and `make config`
shows what is in effect. Every setting can also come from the environment: `STEWART_`, then the keys uppercased and
joined by a double underscore (`apps.hello.options.watch` → `STEWART_APPS__HELLO__OPTIONS__WATCH`).

- Secrets belong in `.env` or in files: `STEWART_HOME_ASSISTANT__TOKEN_FILE=/run/secrets/ha` reads the token from a
  file, which fits Docker and Kubernetes secrets.
- `STEWART_BOOT_*` variables are read by the container entrypoint, not by Stewart; they are listed below.

## Deploying

Pick one. Each runs `ghcr.io/stewart-php/runtime`, an image that holds PHP and an entrypoint but not your code; the
framework version always comes from your `composer.lock`.

### A checkout on the server

```bash
git clone <this repository> my-home && cd my-home
cp .env.example .env    # fill it in
make up                 # installs dependencies on start, then runs
make deploy             # later: git pull, restart; changed dependencies are installed on start
```

### An image built by CI

`.github/workflows/image.yml` builds this repository into `ghcr.io/<owner>/<repository>` for amd64 and arm64 on every
push to `main` and every `v*` tag. Copy `deploy/compose.image.yaml` and a filled-in `.env` to the server, set the
image, and `docker compose -f compose.image.yaml up -d`. Kubernetes runs the same image (below).

### A clone on every start

Copy `deploy/compose.git.yaml` and a filled-in `.env` to the server, set the repository URL, and
`docker compose -f compose.git.yaml up -d`. Every start fetches the ref and installs what changed; restart the service
to deploy. A private repository needs a read-only token (see the comments in the file).

| Entrypoint variable | Meaning |
|---|---|
| `STEWART_BOOT_GIT_URL` | Clone or fetch this repository into `/app` before starting |
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
run Valkey for you. Its README lists every value, including `code.mode=git` for a clone on every start.

## Upgrading

```bash
make update     # composer update; commit composer.lock
```

Dependabot proposes Stewart releases and runtime image tags. A minor release on 0.x may break; read its "Upgrading"
notes in the [changelog](https://github.com/stewart-php/stewart/blob/main/CHANGELOG.md) before merging. Keep the
image tag in `Dockerfile` and the compose files on the same minor as `stewart-php/runtime` in `composer.lock`.

## License

The skeleton this project started from is MIT-0, so none of it needs attribution. `composer.json` declares the project
`proprietary`; change it if you publish your automations under a license.
