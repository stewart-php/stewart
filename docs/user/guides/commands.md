---
title: Commands
description: Every make target, and the Docker context they run in.
sidebar:
  order: 3
---

```
make setup              build + install + doctor, for a first run
make build              build the dev image
make install            composer install
make run                run the daemon
make debug              run with Xdebug enabled
make generate           write typed entity and service classes from Home Assistant
make generate-check     fail if those classes no longer match Home Assistant
make status             one-shot status of the running daemon
make doctor             verify the container
make check              dependency guard + phpstan + php-cs-fixer + tests
make test               unit tests (no Home Assistant needed)
make test-process      spawns real workers; no Home Assistant needed
make test-persistence   storage tests against the Valkey service
make bench              what the container costs a worker at boot
make sh                 shell in the container
```

The documentation site has its own targets:

```
make docs               live-preview at http://localhost:4321
make docs-install       install the site dependencies
make docs-diagrams      render the PlantUML sources to SVG
make docs-clean         remove the built site and its caches
make docs-build         build the site, validating every link
make docs-preview       serve the built site
```

`generate` takes the flags the command does — `--check`, `--snapshot-in`,
`--snapshot-out` — through `vendor/bin/stewart` directly; see
[typed entities and services](codegen.md).

Every target runs in Docker, against whichever Docker context is current.
Override it with `make DOCKER_CONTEXT=desktop-linux ...` to use Docker
Desktop's user-owned socket if you are not in the `docker` group.
