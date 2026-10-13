# Stewart

[![CI](https://github.com/stewart-php/stewart/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/stewart-php/stewart/actions/workflows/ci.yml)
[![Coverage](https://img.shields.io/endpoint?url=https%3A%2F%2Fstewart-php.github.io%2Fstewart%2Freports%2Fbadge-coverage.json)](https://stewart-php.github.io/stewart/reports/coverage/)
[![Mutation MSI](https://img.shields.io/endpoint?url=https%3A%2F%2Fstewart-php.github.io%2Fstewart%2Freports%2Fbadge-msi.json)](https://stewart-php.github.io/stewart/reports/mutation/)
[![Metrics](https://img.shields.io/badge/metrics-phpmetrics-informational)](https://stewart-php.github.io/stewart/reports/metrics/)
[![Documentation](https://img.shields.io/badge/docs-stewart--php.github.io-blue)](https://stewart-php.github.io/docs/)

Write Home Assistant automations in PHP.

An automation is an ordinary PHP class. Stewart holds one WebSocket connection to Home Assistant in a broker
process, keeps the state of every entity, and runs your apps in worker processes, so one misbehaving app does not
stall the connection. Apps subscribe to state changes and events, call services, keep state across restarts in a
key-value store, and get IDE autocomplete over your own entities and services through generated classes.

## Documentation

- **[User documentation](https://stewart-php.github.io/docs/)**: installing, writing and testing apps, recipes,
  deploying, and the configuration, command and error reference.
- **[Developer documentation](https://stewart-php.github.io/docs-dev/)**: contributing, conventions, architecture,
  the protocols and a code walkthrough.

## Using Stewart

Start a project from the skeleton; this repository is where Stewart itself is developed.

```bash
composer create-project stewart-php/skeleton my-home   # or "Use this template" on stewart-php/skeleton
```

[Install Stewart](https://stewart-php.github.io/docs/start/install/) walks through the first run, and
[Deploying](https://stewart-php.github.io/docs/deploy/) covers a checkout on a server, an image built by CI, a clone
that deploys each pushed commit, and Kubernetes through the Helm chart (`oci://ghcr.io/stewart-php/charts/stewart`).
Every setting is in the [configuration reference](https://stewart-php.github.io/docs/reference/configuration/).

## Packages

The repository is a monorepo; each package under `packages/` is installable on its own and is mirrored to a
read-only `stewart-php/<name>` repository for Packagist. All of them, the runtime image, the Helm chart (`charts/`)
and the skeleton (`skeleton/`) share one version; see [releasing](docs/dev/releasing.md).

| Package | Purpose |
|---|---|
| `stewart-php/contracts` | Core abstractions apps code against |
| `stewart-php/runtime` | Broker, workers, the channel between them, and the `stewart` console (`vendor/bin/stewart`) |
| `stewart-php/client` | Home Assistant WebSocket client, usable on its own |
| `stewart-php/store` | Scoping, encoding and hydration for the key-value store; backend-agnostic |
| `stewart-php/store-redis` | Non-blocking Redis/Valkey store backend |
| `stewart-php/mqtt` | Non-blocking MQTT 3.1.1 client the broker uses when `mqtt.url` is set |
| `stewart-php/sun` | Sunrise, sunset, twilight, golden hour and sun position, computed locally |
| `stewart-php/codegen` | Generates typed entity and service classes |
| `stewart-php/support` | Internal helpers shared by the packages; not app-facing |
| `stewart-php/testing` | App harness, recording fakes and virtual time for testing apps; a development dependency |

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

`make help` lists every target. To try a change against Home Assistant, `make demo` runs a project made from
`skeleton/` against this checkout. [Contributing](https://stewart-php.github.io/docs-dev/contributing/) has the rest.

## License

MIT; see [LICENSE](LICENSE). The skeleton is [MIT-0](skeleton/LICENSE), so projects made from it owe no
attribution.
