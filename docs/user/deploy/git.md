---
title: Following a Git branch
description: Let the server clone your repository and deploy every push to a branch, after checking it.
sidebar:
  order: 3
---

In this mode the server holds no checkout. The container clones your repository itself, checks it out at a branch,
and looks for new commits every minute. A new commit is installed next to the running one, checked, and only then
switched to. A push to `main` reaches the server within a minute, and a broken push never replaces a working one.

## Set it up

Copy two files to the server: `deploy/compose.git.yaml` from your project, and a filled-in `.env`. Its
`COMPOSE_PROFILES=store` line starts Valkey next to Stewart; drop it and the store URL when no app takes a `Store`. In
`compose.git.yaml`, set your repository:

```yaml
environment:
  STEWART_BOOT_GIT_URL: https://github.com/you/my-home.git
  STEWART_BOOT_GIT_REF: main
```

Then start it:

```bash
docker compose -f compose.git.yaml up -d
```

## A private repository

Create a token that can only read the repository (on GitHub, a fine-grained token with **Contents: read**). Save it
in a file `git-token` next to `compose.git.yaml`, readable by user 10001, and uncomment the lines for it:

```yaml
    environment:
      STEWART_BOOT_GIT_TOKEN_FILE: /run/secrets/git_token
    secrets:
      - git_token

secrets:
  git_token:
    file: ./git-token
```

For an SSH URL (`git@github.com:you/my-home.git`), use a deploy key instead: mount it as a secret the same way,
readable by user 10001, and set `STEWART_BOOT_GIT_SSH_KEY_FILE: /run/secrets/<name>`.

## What happens on a push

1. Every minute (`STEWART_DEPLOY__GIT__POLL`), Stewart fetches the branch.
2. A new commit is exported into its own directory. If `composer.lock` did not change, the running dependencies are
   reused; otherwise they are installed.
3. The new commit must pass `stewart check`: the configuration loads, and every enabled app can be built with its
   options and the services it takes, such as a `Store` that needs `persistence.url`.
4. If it passes, Stewart stops gracefully and starts again on the new commit, in the same container.
5. If it fails, Stewart logs why, keeps running the old commit, and never tries that commit again. Status shows it
   on the `deploy` row. Push a fix to move on.

The server has no Makefile. Check Stewart there with
`docker compose -f compose.git.yaml exec stewart stewart status`, and follow its log with
`docker compose -f compose.git.yaml logs -f stewart`.

To go back to an older commit, set `STEWART_BOOT_GIT_REF` to it and restart the container.

## Settings

These variables are read by the container's start-up script, not by Stewart:

| Variable | Meaning |
|---|---|
| `STEWART_BOOT_GIT_URL` | The repository to clone |
| `STEWART_BOOT_GIT_REF` | Branch, tag or commit; `main` by default |
| `STEWART_BOOT_GIT_TOKEN_FILE` | A file holding an HTTPS token, for a private repository |
| `STEWART_BOOT_GIT_SSH_KEY_FILE` | A file holding an SSH deploy key, for `git@` URLs |
| `STEWART_BOOT_COMPOSER` | `auto` installs dependencies when `composer.lock` changed, `always`, or `never` |
| `STEWART_BOOT_GENERATE` | `1` regenerates the typed classes from Home Assistant on every start; a failure keeps the committed ones |

`STEWART_DEPLOY__GIT__POLL` sets how often to look for commits (`1m`); `off` deploys only when the container starts.

## Common problems

**The log says a commit was rejected.** Run `make run` or `make stan` on your machine at that commit to see the same
error, fix it, and push.

**`… holds files but no Stewart releases`.** The `app` volume holds something else. Remove the volume
(`docker compose -f compose.git.yaml down -v` also removes Valkey's data) or give it a new name.
