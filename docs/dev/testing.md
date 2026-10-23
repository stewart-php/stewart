---
title: 'Testing'
description: 'The test suites, where tests live, and the fixtures and goldens they use.'
---

## Suites

| Command | Suite | Needs |
|---|---|---|
| `make test` | `packages/*/tests/Unit` | Nothing |
| `make test-integration` | `packages/*/tests/Integration`, `tests/Integration` | Nothing; real sockets in one process |
| `make test-process` | `packages/*/tests/Process` | Valkey; spawns real worker processes |
| `make test-persistence` | `packages/*/tests/Persistence` | Valkey and Mosquitto |
| `make test-architecture` | `tests/Architecture` | Nothing; guards over the whole repository |
| `make test-docs` | `docs/examples/tests` | Nothing; also checks generated classes and reference pages are current |
| `make ha-check` | `integrations/home-assistant/tests` | The Python toolchain image |

`make check` runs all of them. `make test-package PKG=<name>` installs one package on its own and runs its tests, as
its read-only copy would be tested; `make test-packages LOWEST=1` does that for every package with the lowest allowed
dependencies.

`make test-upgrade` builds a project from v1.8.0, with that release's docs examples as its apps, and upgrades it to
this checkout: the project's own `make upgrade` script, then the `bash` block under Upgrading in the `Unreleased`
section of `CHANGELOG.md`. Generate, check, PHPStan and the project's tests must then pass. CI runs it on every change.

The image has `ext-ev`, so the suites run on Revolt's `EvDriver`. To run them on `stream_select()`, which PHP without
`ext-ev` uses, add `REVOLT_DRIVER='Revolt\EventLoop\Driver\StreamSelectDriver'` to the `make` command.
`make loop-driver` prints the driver in use.

## Where tests live

- Each package keeps its tests in `packages/<pkg>/tests/{Unit,Integration,Process,Persistence,Fixtures}`, in the
  namespace `Stewart\<Pkg>\Tests\`, mirroring the source namespace.
- A test may import only what its package requires or requires for development (`PackageManifestTest`).
- Fakes that more than one package needs go in `stewart-php/testing` (`Stewart\Testing\<Area>`); the rest stay in the
  package's `tests/Fixtures`.
- Root `tests/` holds only repository-wide guards (`Architecture`) and console integration tests.
- The examples the user docs include live in `docs/examples/{apps,tests}`, in the namespaces `Automations` and `Automations\Tests`.

## Booting real containers

Tests boot the real container profiles and swap in fakes with `SyntheticServices`, rather than wiring object graphs
by hand. `BrokerKernelFixture` does it for the broker, and `WorkerKernel` takes overrides for a worker.

## Goldens

Some tests compare against files that pin a wire format. Set `UPDATE_GOLDEN=1` to rewrite them after an intended
change, then review the diff:

| Golden | Pins |
|---|---|
| `packages/runtime/tests/Fixtures/Ipc` | The broker–worker channel messages (`WireGoldenTest`) |
| `packages/runtime/tests/Fixtures/Control/Frames` | The control socket frames (`ControlFrameGoldenTest`) |
| `packages/runtime/tests/Fixtures/Metrics/runtime-metrics.txt` | The Prometheus output |
| `packages/codegen/tests/Fixtures/Expected` | Generated code |
| `integrations/home-assistant/tests/protocol` | The `stewart/*` WebSocket commands, replayed by both the PHP and the Python tests |

A changed IPC or control golden means a protocol change: bump the protocol version.

## Time

Unit tests never read the wall clock (`NoWallClockInUnitTestsTest`, `NoRawClockReadRule`). Use `VirtualClock` and
`ManualTimers` from `stewart-php/testing`.

## Quality reports

`make coverage`, `make infection` and `make metrics` produce coverage, mutation and metrics reports in
`var/reports`. CI publishes them to [stewart-php.github.io/stewart](https://stewart-php.github.io/stewart/).
