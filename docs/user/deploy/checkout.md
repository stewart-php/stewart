---
title: A checkout on the server
description: Clone your project onto the server, start it with make up, and update it with make deploy.
sidebar:
  order: 2
---

The simplest way: the server holds a Git checkout of your project and runs it with the same `compose.yaml` you use
during development. You need Git, Docker with Compose, and `make` on the server.

## Set it up

```bash
git clone <your repository> my-home
cd my-home
cp .env.example .env    # fill it in as on your machine; STEWART_CONTROL__TOKEN=$(openssl rand -hex 16)
make up
```

`make up` starts Stewart, and Valkey while `.env` sets `COMPOSE_PROFILES=store`, in the background. On its first start, the container installs your
dependencies, which takes a minute. Then:

```bash
make logs      # follow the log; Ctrl-C stops following, not Stewart
make status    # how the apps are doing
```

Both containers restart on their own after a crash or a reboot.

## Update it

Push your changes from your machine, then on the server:

```bash
make deploy
```

`make deploy` runs `git pull` and restarts Stewart. When `composer.lock` changed, the container installs the new
dependencies before it starts.

## Stop it

```bash
make down
```

Valkey keeps its data in a Docker volume, so stopping and starting loses nothing.

## Common problems

**`make deploy` refuses to pull.** The server's checkout has local changes. Run `git status` there and discard or
commit them.

**Stewart cannot connect after the move.** The server reaches Home Assistant under another address than your
machine. Fix `STEWART_HOME_ASSISTANT__URL` in the server's `.env`.
