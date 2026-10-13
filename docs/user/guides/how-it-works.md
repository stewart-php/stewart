---
title: How it works
description: The main process, the workers that run your apps, and what that means when you write one.
sidebar:
  order: 1
---

Stewart runs as one main process and a few worker processes. Knowing this split explains a few rules you meet when
writing apps.

```
Home Assistant
      │  one WebSocket connection
      ▼
Main process (the broker)
  - talks to Home Assistant
  - keeps a copy of every entity's state
  - passes each event to the workers that need it
      │
      ├──────────────┬──────────────┐
      ▼              ▼              ▼
  Worker 0       Worker 1       Worker 2
  your apps      your apps      your apps
```

**The broker** is the only part that talks to Home Assistant. It logs in, keeps the connection open, reconnects
when it drops, and keeps a copy of the state of every entity.

**Workers** run your apps. Each worker is a separate PHP process with its own copy of all entity states. When an
app calls a service, the worker asks the broker to send it and waits for the answer. When a light changes state,
the broker tells every worker.

`workers` in `stewart.yaml` sets how many workers run. `apps.<id>.worker` puts an app on a chosen worker.

## What this means for your apps

**A crashing app does not take the others down.** If an app throws an error in a handler, Stewart logs it and the
app keeps running. If a whole worker dies, the broker starts it again; the connection to Home Assistant stays open
meanwhile. A worker that keeps crashing is stopped after a few tries, and `make status` shows it.

**Reading a state is instant.** `getState()` reads the worker's own copy, so it never waits for Home Assistant. Call
it as often as you like.

**Calling a service waits for Home Assistant.** `callService()` returns once Home Assistant has answered. Other apps
keep running while one app waits; Stewart pauses only the function that is waiting.

**Do not block the worker.** Apps in a worker share it. A `sleep()`, a slow `file_get_contents()` on a URL, or a
long loop holds up every app on that worker. To wait, use Stewart's tools instead: a schedule, `waitForState()`,
or an option such as `whenChangedTo('off', for: …)`. See [Scheduling and waiting](scheduling.md#waiting-in-a-handler).

**`initialize()` is for setting up, not for waiting.** Stewart delivers nothing to an app until its `initialize()`
returns. Subscribe to streams and add schedules there, then react in the handlers.

**Apps share nothing in memory.** Two apps may run in different processes, so a static variable or a shared object
is not shared between them. Apps exchange data through Home Assistant entities, the
[store](persistence.md), or `publish()` and `watchTopic()`.

## Under the hood

The [architecture page](https://stewart-php.github.io/docs-dev/architecture/) in the developer documentation
describes the processes and the rules that keep them consistent.
