---
title: Running in production
description: Health checks, logs, backups, resources and updates for a Stewart that runs every day.
sidebar:
  order: 6
---

## Health checks

The compose files already check Stewart with `stewart status --probe=readiness`, so `docker compose ps` shows it as
`healthy` or `unhealthy`. Stewart counts as ready when it is connected to Home Assistant and its workers run their
apps.

For other monitoring, turn on the HTTP health port and point your tool at `/healthz` and `/readyz`:

```bash
STEWART_HTTP__LISTEN=tcp://0.0.0.0:8080
```

The compose files publish no ports, so add one to the `stewart` service for tools outside the container:

```yaml
services:
  stewart:
    ports:
      - "8080:8080"
```

The same goes for the admin port (`http.admin.listen`) and a TCP control socket (`control.listen`).

[Status and control](../guides/status.md#health-checks) describes both answers.

## Logs

Stewart logs to standard output: `make logs`, `docker compose logs -f stewart`, or `kubectl logs`. For a log
collector such as Loki or Elasticsearch, switch to one JSON object per line:

```bash
STEWART_LOG_FORMAT=json
```

## Backups

Your code is in Git. What else is worth keeping:

- **`.env`**, with the tokens. Keep a copy somewhere safe, such as a password manager.
- **Valkey's data**, if your apps store anything. Valkey writes it to the `valkey-data` volume as it goes. To copy
  it:

  ```bash
  docker compose exec valkey valkey-cli BGSAVE
  docker compose cp valkey:/data ./valkey-backup
  ```

  To restore, stop Stewart, copy the files back into `/data` of the `valkey` container, and start it again.

## Resources

Stewart runs a main process and one or more workers, each a PHP process. The Helm chart reserves 128 MB of memory and
allows 512 MB, which fits most homes. `make status` shows the memory each process uses. `workers: 0` (the default)
starts one worker per CPU core, at most 4; on a Raspberry Pi with little memory, `workers: 1` or `2` is plenty.

## Updating Stewart

Stewart is a dependency of your project. On your machine:

```bash
make update                # the newest release of your Stewart version, such as 1.2.x
make upgrade VERSION=1.3   # move to another version
```

Read the [upgrade notes](../help/upgrading.md) before you upgrade, run `make generate`, `make stan` and `make test`,
commit `composer.lock` and the changed files, and deploy as usual. Dependabot in your repository proposes new
releases on its own.

If you use the Stewart integration in Home Assistant, update it to the same version.
