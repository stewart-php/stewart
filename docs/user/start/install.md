---
title: Install Stewart
description: Create a project, connect it to Home Assistant, and run it for the first time.
sidebar:
  order: 1
---

By the end of this page you have a Stewart project on your machine, connected to your Home Assistant and running
its example app.

## What you need

- **Docker with Compose.** PHP, Composer and everything else run inside containers, so you install nothing else.
  The images run on amd64 and arm64, so a Raspberry Pi 4 or 5 works too.
- **A Home Assistant you can reach over the network** from the machine running Docker. Stewart is not a Home
  Assistant add-on: it runs in its own container, on the same machine or another one.
- **A long-lived access token from an administrator user.** The next section shows how to make one.

## 1. Create a Home Assistant user for Stewart

Stewart works best with its own Home Assistant user. Its changes are then marked as Stewart's, and an app can tell
them apart from yours. For example, it can tell that *you* switched a light off by hand.

1. In Home Assistant, open **Settings → People** and add a person, for example "Stewart".
2. Allow them to log in, and turn on **Administrator**. Stewart needs an administrator token to listen to all events.
3. Log in as that user, open the profile page, go to the **Security** tab, and create a
   **long-lived access token**. Copy it now, because Home Assistant shows it only once.

## 2. Create your project

Pick one of these. Each creates a folder called `my-home` with your project in it.

**With Docker only.** Composer runs inside the Stewart image:

```bash
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD:/work" -w /work \
  ghcr.io/stewart-php/runtime:1 composer create-project stewart-php/skeleton my-home
```

**With Composer on your machine:**

```bash
composer create-project stewart-php/skeleton my-home
```

**From GitHub.** Open [stewart-php/skeleton](https://github.com/stewart-php/skeleton), click **Use this
template**, then clone your new repository as `my-home`.

## 3. Set it up

```bash
cd my-home
make setup
```

`make setup` pulls the Docker images, creates a `.env` file for your settings, installs the dependencies, and checks
that the container can run Stewart. It ends with:

```
All good.
```

## 4. Connect to Home Assistant

Open `.env` and fill in these two lines:

```bash
STEWART_HOME_ASSISTANT__URL=ws://homeassistant.local:8123/api/websocket
STEWART_HOME_ASSISTANT__TOKEN=paste-the-token-here
```

- The URL is the address you open Home Assistant at. `http://` and `https://` work as well as `ws://` and `wss://`.
- Stewart runs in a container, so `localhost` means the container, not your machine. Use the host name or IP
  address of Home Assistant. If Home Assistant runs on the same machine, `host.docker.internal` reaches it with
  `make run`; on a server, use the machine's IP address.
- `.env` holds secrets. Git already ignores it, so keep it that way.

Now let Stewart read your entities and services:

```bash
make generate
```

This connects to Home Assistant and writes typed PHP classes into `generated/`. If it works, your connection works.
[Typed entities](../guides/codegen.md) explains what the classes are for.

## 5. Run it

```bash
make run
```

Stewart connects, starts the example app `apps/HelloApp.php`, and logs `Hello from Stewart` with the number of
entities it found. After that, the app logs every change of `sun.sun`. It also logs
`The hello entities are not exposed`: that is expected until you install the optional
[Stewart integration](../guides/exposed-entities.md#setting-it-up).

In a second terminal, ask the running Stewart how it is doing:

```bash
make status
```

Press **Ctrl-C** in the first terminal to stop it.

## Common problems

**`The child config "token" under "stewart.home_assistant" must be configured`.** The token in `.env` is empty, or
`.env` is missing. `make env` creates `.env` again from `.env.example`.

**Stewart cannot connect.** Check the URL from inside the container: `make sh`, then `wget -qO- http://your-host:8123`.
`localhost` and `127.0.0.1` point at the container itself.

**`AdministratorRequired`.** The token belongs to a user without administrator rights. Turn on **Administrator**
for that user, or create the token from an administrator user.

**`make` is not found.** On macOS, run `xcode-select --install`. On Debian or Ubuntu, run `sudo apt install make`.

## Next

[Your first app](first-app.md) builds a small automation, tests it, and runs it.
