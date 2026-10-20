---
title: Persistence
description: Keep what an app must remember across restarts, in its own storage or in storage all apps share.
sidebar:
  order: 8
---

An app's properties live in a worker process, and workers restart: after a crash, a deploy, or a change to
`stewart.yaml`. Anything an app must remember longer goes into a **store**. This app counts how often the front door
opens, and the count survives restarts:

```php file=../../examples/apps/DoorCounter.php
```

A `Store` parameter gives the app its own storage, filed under its ID. No other app can read or change it.

## Setting it up

A new project already has it: `compose.yaml` runs a [Valkey](https://valkey.io) server, and `.env` turns it on and
points Stewart at it:

```bash
COMPOSE_PROFILES=store
STEWART_PERSISTENCE__URL=redis://valkey:6379/0
```

When no app takes a `Store`, remove both lines: Valkey then no longer starts.

Any Redis-compatible server works. A password and a database number go in the URL:
`redis://:secret@my-redis:6379/3`. `unix:///path/to/redis.sock` connects over a socket. TLS (`rediss://`) is not
supported.

Without a URL, Stewart runs, but an app that asks for a `Store` does not start; the log names the setting to fill in.
With a URL, Stewart checks the server once at start and refuses to start if it cannot reach it.

Give Stewart a database number of its own rather than sharing one.

## Reading and writing

A key that was never written, or that has expired, reads as `null`:

```php
$opens = $this->store->getInt('opens') ?? 0;
$mode = $this->store->getString('mode');
$target = $this->store->getFloat('target');
$armed = $this->store->getBool('armed');
```

| Method | Does |
|---|---|
| `set('mode', 'away')` | Store a string, number, boolean or object |
| `set('seen', true, Duration::minutes(10))` | The same, forgotten after 10 minutes |
| `increment('opens')` / `increment('budget', -5)` | Add to a whole number; a missing key counts as 0 |
| `has('mode')` | Whether the key exists |
| `delete('mode')` | Remove one key |
| `clear()` | Remove every key of this app |
| `listKeys()` | All keys of this app, sorted |

- Reading a key as the wrong type, such as `getInt()` on text, throws `StoreException` with the reason
  `TypeMismatch`. It means something else wrote that key.
- `set()` without a time limit removes the limit the key had. `increment()` keeps it.
- A key is a letter or digit, then up to 127 letters, digits, `.`, `_`, `:` or `-`. Entity IDs are valid keys, and
  `:` is handy for grouping: `rooms:hall:brightness`.

## Storing objects

A value with structure is a small class that implements `Storable` and says how it is stored:

```php file=../../examples/apps/Thermostat.php
```

```php
$this->store->set('thermostat', new Thermostat(21.5, 'heat'));
$thermostat = $this->store->getObject('thermostat', Thermostat::class);
```

- Only what `toStorage()` returns is stored, as JSON. The class name is not, so you can rename or move the class.
- `toStorage()` returns named fields, not a list. Text must be UTF-8 and numbers finite.
- When you change the fields, make `fromStorage()` accept the old shape too. If it throws, reading fails with the
  reason `ValueRejected`, which names the key and the class.

## Storage all apps share

An attribute on the constructor parameter picks other storage:

```php file=../../examples/apps/AwayMode.php
```

| Parameter | Gets |
|---|---|
| `Store $store` | This app's own storage |
| `ReadableStore $store` | This app's own storage, read-only |
| `#[GlobalStore] Store $house` | Storage every app shares; use it for facts about the house |
| `#[GlobalStore] ReadableStore $house` | The shared storage, read-only |
| `#[PeerStore('away-mode')] ReadableStore $away` | Another app's storage, read-only |

`#[PeerStore]` must be typed `ReadableStore`, so each key has a single writer: the app it belongs to. A peer ID that
does not exist stops the app at start and fails `stewart check`, and the error suggests the closest ID.

A service from `services.php` that asks for a `Store` gets the shared storage, because a service belongs to no app.
Without `persistence.url`, an app that takes a `Store`, or a service it uses that takes one, does not start, and
`stewart check` names it. A service marked `public()` in `services.php` that takes a `Store` stops its whole
worker instead.

## When the server is down

A failed store call throws `StoreException`, with the reason `Unreachable`, `Refused`, `TimedOut` or
`RecentlyFailed`. In a handler, Stewart logs it as a failure and the app keeps running. In `initialize()`, the app
does not start.

After a timeout or a lost connection, a worker fails every store call at once for `persistence.recovery_interval`
(5 seconds) with `RecentlyFailed`, so an outage costs one timeout, not one per handler. `make status` shows the store
as down meanwhile.

Two things to know before relying on a write:

- `increment()` is safe against other writers, but a dropped connection is retried, so it can count twice. Use it
  for counting, not for billing.
- A write that throws may still have been saved.

## Looking at the data

Values are stored as plain text and JSON, so you can read them with `valkey-cli`:

```
stewart:app:door-counter:opens  ->  42
stewart:app:heating:thermostat  ->  {"target":21.5,"mode":"heat"}
stewart:global:mode             ->  "away"
```

```bash
docker compose exec valkey valkey-cli --scan --pattern 'stewart:*'
```

`stewart` is `persistence.prefix`. Change it when two Stewart installations share one server.

## Testing

The test harness gives apps an in-memory store. `$harness->stores->openAppStore(new AppId('…'))` and
`openGlobalStore()` read and seed it:

```php file=../../examples/tests/AwayModeTest.php
```

## Common problems

**`Persistence is not configured`.** Set `STEWART_PERSISTENCE__URL` in `.env`.

**Stewart does not start: the store is unreachable.** Check that the `valkey` container runs (`docker compose ps`) and
that the URL names it. Valkey starts only while `.env` has `COMPOSE_PROFILES=store`.

**`Key "…" holds …, not …`.** Two apps, or two versions of one app, wrote different types under the same key.
