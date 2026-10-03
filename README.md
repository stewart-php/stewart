# Stewart

Write Home Assistant automations in PHP.

An automation is an ordinary PHP class. Stewart holds one WebSocket connection to Home Assistant in a broker
process, keeps the state of every entity, and runs your apps in worker processes, so one misbehaving app does not
stall the connection. Apps subscribe to state changes and events, call services, keep state across restarts in a
key-value store, and get IDE autocomplete over your own entities and services through generated classes.

## Using Stewart

Start a project from the skeleton; this repository is where Stewart itself is developed.

```bash
composer create-project stewart-php/skeleton my-home   # or "Use this template" on stewart-php/skeleton
```

The skeleton's README covers development, generated classes and deployment: a checkout on a server, an image built by
CI, a clone on every start, or Kubernetes through the Helm chart (`oci://ghcr.io/stewart-php/charts/stewart`). Each
runs `ghcr.io/stewart-php/runtime`, which holds PHP and an entrypoint; the framework version comes from the project's
`composer.lock`.

## Configuration

A project's `stewart.yaml` holds only what differs from the defaults. `vendor/bin/stewart config:reference` prints
every setting with its default; `vendor/bin/stewart config:dump` prints what is in effect, secrets masked.

Every setting can also come from the environment: the `STEWART_` prefix, then the keys uppercased and joined by a
**double** underscore. A single underscore is part of a key name.

| `stewart.yaml` | Environment variable |
|---|---|
| `log_level` | `STEWART_LOG_LEVEL` |
| `worker_event_buffer` | `STEWART_WORKER_EVENT_BUFFER` |
| `apps.hello.worker` | `STEWART_APPS__HELLO__WORKER` |
| `apps.hello.options.watch` | `STEWART_APPS__HELLO__OPTIONS__WATCH` |

- Environment variables win over the file; `--config`, `--log-level` and `--json-logs` on the command line win over
  both.
- Durations take a unit: `500ms`, `5s`, `2m`, `1h`. A bare number is refused. Delays and windows are at least `1ms`,
  a `max_delay` is at least its `initial_delay`, and `supervision.restart_window` must outlast the backoff of
  `restart_attempts` restarts.
- The word `off` switches off a timer or the control socket. An empty variable means unset, so the default applies.
- A list setting or an app option takes inline YAML: `STEWART_CODEGEN__INCLUDE='[light.*, switch.*]'`. An app option
  that is not valid inline YAML stays text, so a template or a regex passes through.
- A string in `stewart.yaml` can read a variable, with a fallback: `token: ${HA_TOKEN}`, `${LOG_LEVEL:-info}`. A value
  that is exactly one placeholder is typed like its setting, so `workers: ${POOL:-2}` is a number.
- Kubernetes service links (`STEWART_SERVICE_HOST`, `STEWART_PORT_…`) are ignored; any other unknown `STEWART_`
  variable stops the start.

## Packages

The repository is a monorepo; each package under `packages/` is installable on its own and is mirrored to a
read-only `stewart-php/<name>` repository for Packagist. All of them, the runtime image, the Helm chart (`charts/`)
and the skeleton (`skeleton/`) share one version; see [RELEASING.md](RELEASING.md).

| Package | Purpose |
|---|---|
| `stewart-php/contracts` | Core abstractions apps code against |
| `stewart-php/runtime` | Broker, workers, the channel between them, and the `stewart` console (`vendor/bin/stewart`) |
| `stewart-php/client` | Home Assistant WebSocket client, usable on its own |
| `stewart-php/store` | Scoping, encoding and hydration for the key-value store; backend-agnostic |
| `stewart-php/store-redis` | Non-blocking Redis/Valkey store backend |
| `stewart-php/codegen` | Generates typed entity and service classes |
| `stewart-php/support` | Internal helpers shared by the packages; not app-facing |
| `stewart-php/testing` | Shared test doubles; a development dependency |

## Development

Docker with Compose is all the host needs; PHP and every tool run in the container.

```bash
make setup                          # build the image, install dependencies, verify the container
make check                          # PHPStan, code style and every test suite
make test-package PKG=client        # install one package on its own and run its tests
make test-packages LOWEST=1         # every package, against its lowest allowed dependencies
make test-skeleton                  # install skeleton/ against this checkout and run its first steps
make test-image                     # build the runtime image and smoke-test its entrypoint
make chart-lint                     # lint the Helm chart and validate what it renders
make cs-fix                         # fix code style
```

`make help` lists every target. To try a change against Home Assistant, run a project made from the skeleton with a
Composer path repository pointing at this checkout's `packages/*`, as `bin/test-skeleton.sh` does.

## License

MIT; see [LICENSE](LICENSE). The skeleton is [MIT-0](skeleton/LICENSE), so projects made from it owe no
attribution.
