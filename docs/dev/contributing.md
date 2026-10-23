---
title: 'Contributing'
description: 'Set up the monorepo, find your way around it, and get a change merged.'
---

Everything about Stewart lives in [stewart-php/stewart](https://github.com/stewart-php/stewart): the PHP packages,
the runtime image, the Helm chart, the project skeleton, the Home Assistant integration and these docs. The other
`stewart-php/*` repositories are read-only copies made at release.

## Setting up

You need Docker with Compose, `make` and Git. PHP, Composer, Node and Python run in containers.

```bash
git clone git@github.com:stewart-php/stewart.git
cd stewart
make setup      # build the dev image, install dependencies, check the container
make check      # every check CI runs
```

## Finding your way

| Path | Holds |
|---|---|
| `packages/contracts` | Everything apps code against: interfaces, value objects, exceptions. No runtime code |
| `packages/runtime` | The broker, the workers, the channel between them, the console commands, configuration |
| `packages/client` | The Home Assistant WebSocket client |
| `packages/codegen` | `stewart generate`: typed entity and service classes |
| `packages/store`, `packages/store-redis` | Key-value storage, and its Redis backend |
| `packages/mqtt` | The MQTT client |
| `packages/sun` | Sun times and positions |
| `packages/testing` | `AppHarness` and the fakes apps are tested with |
| `packages/phpstan` | PHPStan rules that check entity IDs and action names against the generated `Manifest` |
| `packages/support` | Internal helpers with no API promise |
| `skeleton/` | The template new projects start from |
| `integrations/home-assistant/` | The Home Assistant integration, in Python |
| `charts/stewart/` | The Helm chart |
| `.docker/` | The runtime image and the dev toolchain |
| `docs/` | User docs (`user/`), these docs (`dev/`), tested examples, the docs generator and the site |
| `tests/` | Repository-wide architecture guards, and console integration tests |

[Architecture](architecture.md) explains how the packages fit together.

## Everyday commands

| Command | Does |
|---|---|
| `make check` | Everything: PHPStan, code style, all test suites, the integration's checks |
| `make stan`, `make cs`, `make cs-fix` | Static analysis; check and fix code style |
| `make test` | Unit tests; see [Testing](testing.md) for the other suites |
| `make test-package PKG=store` | Install one package alone and run its tests, as its read-only copy would |
| `make sh` | A shell in the dev container |
| `make demo` | Run a project made from `skeleton/` against your own Home Assistant, in `var/demo` |
| `make ha-check` | Lint, type-check and test the Home Assistant integration |
| `make docs-user`, `make docs-dev` | Preview the docs at `localhost:4321/docs/` and `localhost:4322/docs-dev/` |
| `make docs-reference` | Regenerate the reference pages after changing config, commands, errors or metrics |

`make help` lists every target.

## Making a change

1. Branch from `main`.
2. Make the change, with tests. Follow the [conventions](conventions.md); several of them are enforced by PHPStan
   rules and architecture tests.
3. Add a line to the `Unreleased` section of `CHANGELOG.md` when users notice the change, ending with the pull
   request: `([#94](https://github.com/stewart-php/stewart/pull/94))`.
   A change that renames something apps use also extends the `bash` block under its Upgrading heading, which
   `make test-upgrade` runs on a 1.8 project.
4. Update the user docs in `docs/user/` when behaviour changes, and run `make docs-reference` when a setting,
   command, error or metric changed.
5. Run `make check`, push, and open a pull request.

Commit messages are `type(component): few-word summary`, an empty line, then a few short `- ` bullets naming the main
changes. Types are the usual `feat`, `fix`, `docs`, `refactor`, `test`, `ci`, `chore`.

## Questions and bugs

Open an issue on [stewart-php/stewart](https://github.com/stewart-php/stewart/issues). The read-only copies have no issues,
and pull requests there are closed automatically.
