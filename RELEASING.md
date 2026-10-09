# Releasing

This repository is the only place Stewart is developed. Everything else is published from it.

| Artifact | Where | Built by |
|---|---|---|
| `stewart-php/{contracts,support,client,store,store-redis,mqtt,sun,runtime,codegen,testing}` | Read-only `stewart-php/<name>` repositories, Packagist | `split.yml` |
| `stewart-php/skeleton` | Read-only `stewart-php/skeleton` (a GitHub template repository), Packagist | `split.yml` |
| Home Assistant integration | Read-only `stewart-php/hass-stewart`, its releases carry `stewart.zip` for HACS | `split.yml` |
| `ghcr.io/stewart-php/runtime`, `…-dev` | GHCR, linux/amd64 and linux/arm64 | `release.yml`, `rebuild.yml` via `runtime-image.yml` |
| `oci://ghcr.io/stewart-php/charts/stewart` | GHCR | `release.yml` |
| Release notes | GitHub releases | `release.yml`, from `CHANGELOG.md` |

## Versioning

- **Lockstep.** One version for every package, the image, the chart, the skeleton and the Home Assistant
  integration. A tag `vX.Y.Z` releases all of them, even the ones that did not change. Packages require each other
  with `self.version`, so an installation never mixes versions.
- **Semver from 1.0.** On 0.x a minor release (`0.2.0`) may break, and its changelog section has an "Upgrading" part. A
  patch never breaks. From 1.0, the public surface is what apps code against: `stewart-php/contracts`, the generated
  classes, configuration keys, environment variables, console commands and the image entrypoint settings.
- **Trunk and tags.** Releases are cut from `main`. On 0.x a fix for an older minor is not backported; upgrade instead.
- **Branch alias.** `dev-main` is `0.x-dev` until 1.0, then `1.x-dev`; change it in every `packages/*/composer.json`,
  the root path repository's `versions`, `PackageManifestTest::BRANCH_ALIAS` and `bin/test-package.sh` together.

## Image tags

The runtime image holds PHP, the extensions, Composer, git and the entrypoint; a project's `composer.lock` decides the
framework version. Its tags follow the release:

- `X.Y.Z` and `X.Y.Z-dev`: the release as built, never changed afterwards.
- `X.Y` and `X.Y-dev`: rebuilt every week from the latest `X.Y.Z` by `rebuild.yml`, picking up PHP and Alpine fixes.
  Projects use these.
- `X` and `X-dev`: from 1.0 on, the same for the latest minor of a major.
- No `latest`, and no rolling `0`, since every 0.x minor may break.

The image uses the newest PHP minor that CI proves (`PHP_VERSION` in `.docker/php/Dockerfile`). Move it only in a
minor release, after `make check` is green on that PHP in CI, and say so in the changelog.

## Cutting a release

1. Move the `Unreleased` entries in `CHANGELOG.md` under `## [X.Y.Z] - YYYY-MM-DD`. For a new minor, add the
   "Upgrading" notes.
2. For a new minor, point the skeleton at it: `^X.Y` for the `stewart-php/*` requirements in
   `skeleton/composer.json`, and `ghcr.io/stewart-php/runtime:X.Y` (`X.Y-dev` in `compose.dev.yaml`) in
   `skeleton/Dockerfile`, `skeleton/compose.yaml`, `skeleton/compose.dev.yaml` and `skeleton/deploy/*.yaml`.
   `release.yml` refuses a tag that does not match.
3. Set `version` in `integrations/home-assistant/custom_components/stewart/manifest.json` to `X.Y.Z`; `release.yml`
   refuses a tag that does not match.
4. Commit, then tag and push: `git tag -s vX.Y.Z -m vX.Y.Z && git push origin main vX.Y.Z`.
5. Watch `Split` and `Release`. When they are green, `composer create-project stewart-php/skeleton` in a scratch
   directory should install `X.Y.Z`, and `stewart-php/hass-stewart` should have an `X.Y.Z` release with `stewart.zip`.

A broken release is fixed by a new patch release, never by moving or deleting a tag.

## One-time setup

Done once, by an owner of the `stewart-php` organization, before `v0.1.0`.

1. **Repositories.** Create `client`, `codegen`, `contracts`, `mqtt`, `runtime`, `store`, `store-redis`, `sun`,
   `support`, `testing`, `skeleton` and `hass-stewart` under `stewart-php`, each empty, with issues, wiki and projects
   off. Their description says they are read-only splits. Mark `skeleton` as a template repository. Give
   `hass-stewart` the topics `home-assistant`, `hacs` and `integration`, which HACS validation checks.
2. **Split token.** Create a GitHub App owned by `stewart-php` with *Contents* and *Workflows: read and write* (every
   split carries `.github/workflows`), install it on those twelve repositories, then add its client ID as the variable
   `SPLIT_APP_CLIENT_ID` and its private key as the secret `SPLIT_APP_PRIVATE_KEY` in this repository. Until the
   variable exists, `Split` is skipped.
3. **Packagist.** Run `Split` from the Actions tab so it fills the repositories. Then submit each of the eleven PHP
   ones on packagist.org under the `stewart-php` vendor and enable the GitHub hook. Optionally, add
   `PACKAGIST_USERNAME` (variable) and `PACKAGIST_TOKEN` (secret) so `split.yml` also triggers an update itself.
4. **GHCR.** After the first release, open the `runtime` and `charts/stewart` packages in the organization's
   packages, make them public, and link them to this repository.
5. **Docker Hub.** Create a read-only Docker Hub access token, then add the username as the variable
   `DOCKERHUB_USERNAME` and the token as the secret `DOCKERHUB_TOKEN`; image builds log in with them so Docker Hub's
   anonymous rate limit does not fail them.
6. **Runners.** `ubuntu-24.04-arm` runners must be available to the organization; they are free for public
   repositories.
7. **HACS.** Until `hass-stewart` is in the HACS default store, users add it as a custom repository (category
   *Integration*); its README says how.
8. **Optional.** List the chart on Artifact Hub (`oci://ghcr.io/stewart-php/charts/stewart`).

## Verifying a published release

```bash
cosign verify ghcr.io/stewart-php/runtime:X.Y.Z \
  --certificate-identity-regexp 'https://github.com/stewart-php/stewart/' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
docker buildx imagetools inspect ghcr.io/stewart-php/runtime:X.Y.Z --format '{{json .Provenance}}'
helm show chart oci://ghcr.io/stewart-php/charts/stewart --version X.Y.Z
```
