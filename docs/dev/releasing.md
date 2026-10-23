---
title: 'Releasing'
description: 'What gets published where, how versions and image tags work, and how to cut a release.'
---

This repository is the only place Stewart is developed. Everything else is published from it.

| Artifact | Where | Built by |
|---|---|---|
| `stewart-php/{contracts,support,client,store,store-redis,mqtt,sun,runtime,codegen,testing,phpstan}` | Read-only `stewart-php/<name>` repositories, Packagist | `split.yml` |
| `stewart-php/skeleton` | Read-only `stewart-php/skeleton` (a GitHub template repository), Packagist | `split.yml` |
| Home Assistant integration | Read-only `stewart-php/hass-stewart`, its releases carry `stewart.zip` for HACS | `split.yml` |
| `ghcr.io/stewart-php/runtime`, `…-dev` | GHCR, linux/amd64 and linux/arm64 | `release.yml`, `rebuild.yml` via `runtime-image.yml` |
| `oci://ghcr.io/stewart-php/charts/stewart` | GHCR | `release.yml` |
| Release notes | GitHub releases | `release.yml`, from `docs/releases/X.Y.Z.md` |
| Changelog | `/docs-dev/changelog/`, read from `CHANGELOG.md` | `docs.yml` |
| Documentation | `stewart-php.github.io/docs` and `/docs-dev`, from `stewart-php/stewart-php.github.io` | `docs.yml` |

## Versioning

- **Lockstep.** One version for every package, the image, the chart, the skeleton and the Home Assistant
  integration. A tag `vX.Y.Z` releases all of them, even the ones that did not change. Packages require each other
  with `self.version`, so an installation never mixes versions.
- **Semver.** Only a major release breaks, and its changelog section has an "Upgrading" part. The public surface is
  what apps code against: `stewart-php/contracts`, the generated classes, configuration keys, environment variables,
  console commands and the image entrypoint settings. `make bc-check` guards the PHP part: `contracts` and `testing`,
  which apps and their tests import. The other packages may change their classes in a minor.
- **What may grow in a minor.** `HaContext`, `Scheduler`, `Timers` and the other interfaces Stewart implements for
  apps may gain methods, and the `*Error` reason enums may gain cases. Add each new method to the package's roave
  baseline (`.roave-backward-compatibility-check.xml`), as `HaContext::callAction()` was in 1.8; the contracts and
  testing baselines already allow new reasons.
- **What never grows in a minor.** Interfaces that generated code implements (`Manifest`, `TypedEntity`,
  `ActionTargetSource`): a project that has not run `stewart generate` since the upgrade would fail to load its
  classes. New generated members go on a new interface instead, until the next major folds it in.
- **Trunk and tags.** Releases are cut from `main`. A fix for an older minor is not backported; upgrade instead.
- **Branch alias.** `dev-main` is `X.x-dev` for the current major; at a new major change it in every
  `packages/*/composer.json`, the root path repository's `versions`, `PackageManifestTest::BRANCH_ALIAS`,
  `bin/test-package.sh` and `bin/bc-check.sh` together.

## Image tags

The runtime image holds PHP, the extensions, Composer, git and the entrypoint; a project's `composer.lock` decides the
framework version. Its tags follow the release:

- `X.Y.Z` and `X.Y.Z-dev`: the release as built, never changed afterwards.
- `X.Y` and `X.Y-dev`: the latest `X.Y.Z`. `rebuild.yml` rebuilds only the latest release every week, so an older
  minor's tag stops getting PHP and Alpine fixes.
- `X` and `X-dev`: the latest minor of a major, rebuilt every week. Projects use these.
- No `latest`, and no rolling `0`.

The image uses the newest PHP minor that CI proves (`PHP_VERSION` in `.docker/php/Dockerfile`). Move it only in a
minor release, after `make check` is green on that PHP in CI, and say so in the changelog.

## Cutting a release

1. Move the `Unreleased` entries in `CHANGELOG.md` under `## [X.Y.Z] - YYYY-MM-DD`. When projects have
   something to change, add "Upgrading" notes.
2. Write `docs/releases/X.Y.Z.md`, the GitHub release text: a short paragraph on what the release is about, the
   three to five changes users will care about most with a sentence each, the rest in a short list, and how to
   upgrade. Write it for people who use Stewart, not for the people who build it; the changelog keeps every detail.
   `release.yml` refuses a tag without it and adds a link to the changelog.
3. For a new minor, point the skeleton at it: `^X.Y` for the `stewart-php/*` requirements in
   `skeleton/composer.json`. For a new major, also `ghcr.io/stewart-php/runtime:X` (`X-dev` in `compose.dev.yaml`) in
   `skeleton/Dockerfile`, `skeleton/compose.yaml`, `skeleton/compose.dev.yaml` and `skeleton/deploy/compose.git.yaml`.
   `release.yml` refuses a tag that does not match.
4. Set `version` in `integrations/home-assistant/custom_components/stewart/manifest.json` to `X.Y.Z`; `release.yml`
   refuses a tag that does not match.
5. Commit and `git push origin main`, then wait for `CI` to pass on that commit. If GitHub starts no run for the push,
   start one with `gh workflow run ci.yml --ref main`.
6. Tag and push the tag: `git tag -s vX.Y.Z -m vX.Y.Z && git push origin vX.Y.Z`. `release.yml` refuses a commit
   without a passing `CI` run; once CI passes, re-run the failed jobs instead of moving the tag. It runs the
   `Distribution` checks before it builds the image, and the image is scanned before it is tagged and signed.
7. Watch `Split`, `Release` and `Docs`. When they are green, `composer create-project stewart-php/skeleton` in a scratch
   directory should install `X.Y.Z`, and `stewart-php/hass-stewart` should have an `X.Y.Z` release with `stewart.zip`, and
`https://stewart-php.github.io/docs/` should show the new version's pages.

A broken release is fixed by a new patch release, never by moving or deleting a tag.

## One-time setup

Done once, by an owner of the `stewart-php` organization, before `v0.1.0`.

1. **Repositories.** Create `client`, `codegen`, `contracts`, `mqtt`, `phpstan`, `runtime`, `store`, `store-redis`,
   `sun`, `support`, `testing`, `skeleton` and `hass-stewart` under `stewart-php`, each empty, with issues, wiki and projects
   off. Their description says they are read-only splits. Mark `skeleton` as a template repository. Give
   `hass-stewart` the topics `home-assistant`, `hacs` and `integration`, which HACS validation checks.
2. **Split token.** Create a GitHub App owned by `stewart-php` with *Contents* and *Workflows: read and write* (every
   split carries `.github/workflows`), install it on those thirteen repositories, then add its client ID as the variable
   `SPLIT_APP_CLIENT_ID` and its private key as the secret `SPLIT_APP_PRIVATE_KEY` in this repository. Until the
   variable exists, `Split` is skipped.
3. **Packagist.** Run `Split` from the Actions tab so it fills the repositories. Then submit each of the twelve PHP
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
8. **Documentation site.** Create the public repository `stewart-php/stewart-php.github.io` with a `main` branch
   holding a `.nojekyll` file and a placeholder `index.html`. In its settings, set Pages to deploy from the `main`
   branch, root folder. Install the split GitHub App on it too (it needs *Contents: read and write*). Never create a
   `stewart` directory there: `stewart-php.github.io/stewart/` belongs to this repository's quality reports. Then run
   **Docs** by hand once.
9. **Optional.** List the chart on Artifact Hub (`oci://ghcr.io/stewart-php/charts/stewart`).

## Documentation

- `help/upgrading.md` in the user docs is generated from the "Upgrading" sections of `CHANGELOG.md`. After editing
  them, run `make docs-reference`; `make test-docs` fails until you do.
- The developer site reads `CHANGELOG.md` itself and shows it as `/docs-dev/changelog/`
  (`docs/site/src/content.config.ts`), so it changes with every published tag.
- `docs.yml` builds both sites from the release tag and pushes them into `docs/` and `docs-dev/` of
  `stewart-php/stewart-php.github.io`. It keeps the site root (a landing page), `/docs/<major>.x/` and `/docs/next/`.
- Every push to `main` that changes `docs/` publishes the user docs of `main` to `/docs/next/`, with a banner and
  `noindex`; `make docs-build-next` builds it locally.
- A docs-only fix after a release: merge it, then run **Docs** by hand with the ref to publish (empty means the
  latest tag; `main` publishes unreleased docs, so only do that on purpose).
- **At a new major**, before its first publish: copy the current `docs/` of the site repository to `docs/<old>.x/`,
  add a note on its pages that they describe an old version, and commit it. Then tag the release.

## Verifying a published release

```bash
cosign verify ghcr.io/stewart-php/runtime:X.Y.Z \
  --certificate-identity-regexp 'https://github.com/stewart-php/stewart/' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
docker buildx imagetools inspect ghcr.io/stewart-php/runtime:X.Y.Z --format '{{json .Provenance}}'
helm show chart oci://ghcr.io/stewart-php/charts/stewart --version X.Y.Z
```
