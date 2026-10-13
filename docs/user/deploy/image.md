---
title: An image built by CI
description: Let GitHub build your project into a Docker image on every push, and run that image on the server.
sidebar:
  order: 4
---

Your project's `.github/workflows/image.yml` builds your code and its dependencies into a Docker image on every push
to `main` and every `v*` tag, for amd64 and arm64. The server then runs that image. It needs no Git, no Composer and
no access to your repository; what runs is exactly what CI built.

## Set it up

1. Push your project to GitHub. The **Image** workflow publishes `ghcr.io/<owner>/<repository>`.
2. If the repository is private, the image is private too. Log the server in to the registry with a token that can
   read packages: `docker login ghcr.io`.
3. Copy `deploy/compose.image.yaml` and a filled-in `.env` to the server, and set the image in the file:

   ```yaml
   services:
     stewart:
       image: ghcr.io/you/my-home:main
   ```

4. Start it:

   ```bash
   docker compose -f compose.image.yaml up -d
   ```

## Tags

| Tag | Points at |
|---|---|
| `main` | The latest push to `main` |
| `1.2.0` | The tag `v1.2.0`, if you tag releases |
| `sha-abc1234` | One exact commit |

## Update it

After CI has built a new image:

```bash
docker compose -f compose.image.yaml pull
docker compose -f compose.image.yaml up -d
```

To roll back, set the image to an older tag, such as `sha-abc1234`, and run the same two commands.

## The Dockerfile

Your project's `Dockerfile` starts from the runtime image of your Stewart version, installs the dependencies without
development packages, and copies your code in. Keep its `FROM` lines on the same Stewart version as `composer.lock`;
`make upgrade` changes both.

## Common problems

**`denied` when pulling.** The server is not logged in to `ghcr.io`, or the token cannot read packages.

**The server still runs the old code.** `up -d` reuses a pulled image only after `pull`. Run both commands.
