---
title: Project tour
description: What each file and folder in a Stewart project is for, and the make commands you use day to day.
sidebar:
  order: 3
---

A new project from the skeleton looks like this. You mostly work in `apps/`, `tests/` and `stewart.yaml`.

```
my-home/
├── apps/                 your automations
├── tests/                tests for them
├── generated/            typed classes from your Home Assistant (make generate)
├── services.php          your own services, passed to apps
├── stewart.yaml          settings and app options
├── .env                  secrets and per-machine settings (not in Git)
├── .env.example          the template .env is made from
├── compose.yaml          how Stewart and Valkey run
├── compose.dev.yaml      development additions: Xdebug, no automatic installs
├── Dockerfile            builds an image of your project
├── deploy/               compose files for a server
├── Makefile              the make commands
├── composer.json         dependencies, including the Stewart version
├── phpstan.neon.dist     static analysis settings
├── phpunit.xml.dist      test settings
├── .github/              CI and image builds on GitHub, and Dependabot
├── bin/                  helper scripts for make
└── vendor/               installed dependencies (not in Git)
```

## Your code

**`apps/`** holds one class per automation. Every class with `#[Automation]` that implements `App` is an app;
there is no list to keep up to date. The namespace is `App`. You can put other classes here too, such as
value objects or helpers that several apps share.

**`tests/`** holds PHPUnit tests, in the namespace `App\Tests`. `make test` runs them. See
[Testing apps](../guides/testing.md).

**`generated/`** holds classes that `make generate` writes from your Home Assistant: one per entity domain, with
methods for its services. Commit them, so a build or a server never needs Home Assistant to create them. Run
`make generate` again after you add devices. See [Typed entities](../guides/codegen.md).

**`services.php`** registers your own classes, such as a weather API client, so apps can ask for them in their
constructor. Leave it as it is until you need it:

```php
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire();
    $services->set(App\Weather\Forecast::class);
};
```

## Settings

**`stewart.yaml`** holds the settings that differ from the defaults, and the options of your apps. It belongs in Git,
so keep secrets out of it.

```yaml
log_level: info

apps:
  porch-light:
    options:
      light: light.front_porch
```

**`.env`** holds secrets (the Home Assistant token) and settings that differ per machine. Every setting can live
here instead of in `stewart.yaml`: write `STEWART_`, then the keys in capitals, joined by two underscores.
`log_level` becomes `STEWART_LOG_LEVEL`, and `home_assistant.token` becomes `STEWART_HOME_ASSISTANT__TOKEN`.

To see every setting with its default, run `make config-reference`. To see what is in effect right now, run
`make config`; secrets are masked.

## Running

**`compose.yaml`** runs two containers: `stewart`, from the `ghcr.io/stewart-php/runtime` image with your project
mounted into it, and `valkey`, which stores what apps must remember across restarts. The runtime image holds PHP
and Stewart's start-up script. Your code and the Stewart version come from your project.

**`compose.dev.yaml`** is added for development commands such as `make run` and `make test`. It uses the image
with Xdebug, and installs dependencies only when you run `make install`.

**`Dockerfile`** and **`deploy/`** are for servers. See
[Deploying](../deploy/index.md).

**`.github/workflows/`** runs `make stan` and `make test` on every push (`ci.yml`), and builds an image of your
project (`image.yml`). Dependabot proposes Stewart updates.

## make commands

`make help` lists them all, and the [command reference](../reference/cli.md) describes each with the `stewart`
commands. These are the ones you use most:

| Command | What it does |
|---|---|
| `make setup` | First run: pull images, create `.env`, install dependencies, check the container |
| `make run` | Run in the foreground; Ctrl-C stops it. `ONLY="porch-light"` runs only those apps, `DRY_RUN=1` sends no service calls |
| `make debug` | Like `make run`, with Xdebug on |
| `make test` | Run the tests |
| `make stan` | Run PHPStan |
| `make generate` | Write the typed classes from Home Assistant into `generated/` |
| `make config` | Show the settings in effect |
| `make config-reference` | Show every setting with its default |
| `make sh` | Open a shell in the container |
| `make up` / `make down` | Start Stewart in the background / stop it |
| `make logs` | Follow the log of the background Stewart |
| `make status` | Ask the running Stewart how its apps are doing |
| `make update` | Update dependencies within your Stewart version |
| `make upgrade VERSION=…` | Move to another Stewart version |
