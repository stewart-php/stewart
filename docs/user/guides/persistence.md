---
title: Persistence
description: Keep what an automation must remember across restarts, in its own scope or a shared one.
sidebar:
  order: 4
---

An automation's properties live in a worker process, and a worker restarts — on
a crash, on a reload, on a deploy. Anything that has to outlive that goes in a
store.

```php file=../../examples/apps/HeatingApp.php
```

A parameter typed `Store` gets that automation's own scope, keyed by the ID in
its `#[Automation]` attribute. Nothing has to be configured for that, and no
other automation can read or write those keys.

## Setting it up

Storage is off until a URL is set. Point it at the `valkey` service that ships
in `docker-compose.yml`, or at any Redis-compatible server:

```bash
STEWART_PERSISTENCE__URL=redis://valkey:6379/0
```

A password and a database index belong in the URL —
`redis://:secret@valkey:6379/3`. Only the password form of `AUTH` is spoken, so
a Redis ACL *username* is ignored. `rediss://` is not supported yet.

The rest is in `stewart.yaml`:

```yaml
persistence:
  prefix: stewart   # what every key is filed under, so two installations can share a server
  timeout: 2s       # how long one operation may take
  recovery_interval: 5s  # after a failure, how long store calls fail at once before one is tried again
```

With no URL set, Stewart runs exactly as before, and an automation that asks for
a `Store` is not started — it is reported at startup, naming the setting to
write, while the other automations run. With a URL set, the daemon reaches the
store once before it connects to Home Assistant and refuses to start if it
cannot: a store that is not there at boot is a mistake in the config, not an
outage.

Give Stewart a database index of its own (`/0`, `/3`, …) rather than sharing one
with something else. Listing an automation's keys scans the keyspace, which is
cheap when the keyspace is yours.

## Reading and writing

Each type has its own accessor, and a key that was never written — or has
expired — reads as `null`:

```php
$count  = $this->store->int('opens') ?? 0;
$mode   = $this->store->string('mode');
$target = $this->store->float('target');
$armed  = $this->store->bool('armed');

if (!$this->store->has('mode')) { … }
```

Asking for a type the key does not hold throws `StoreError::TypeMismatch` rather than
returning `null`, because it means something other than this reader wrote it.

```php
$this->store->set('mode', 'away');
$this->store->set('seen', true, Duration::minutes(10));   // forgotten after ten minutes
$this->store->delete('mode');
$this->store->clear();                                     // this scope only
$this->store->keys();                                      // sorted, without the prefix
```

`set()` without a TTL clears an expiry the key had. `increment()` adds to a
whole number, treats a missing key as `0`, and keeps any expiry:

```php
$opens = $this->store->increment('opens');       // 1, then 2, …
$this->store->increment('budget', -5);
```

Keys match `[A-Za-z0-9][A-Za-z0-9._:-]{0,127}`, so an entity ID is a valid key
and `:` is free for sub-namespacing (`rooms:hall:brightness`).

## Storing objects

Anything with structure is a value object that says how it is stored:

```php file=../../examples/apps/Thermostat.php
```

```php
$this->store->set('thermostat', new Thermostat(21.5, 'heat'));
$thermostat = $this->store->getObject('thermostat', Thermostat::class);
```

The reader names the class, and nothing about it is stored — so renaming or
moving the class costs nothing. What *is* stored is whatever `toStorage()`
returned, which makes the shape the class's own to look after: when it changes,
`fromStorage()` is where an older shape is absorbed. If it cannot, it throws,
and the read fails with `StoreError::ValueRejected` naming the key and the class.

`toStorage()` must return a map of named fields; a list is refused. Strings must
be UTF-8 and floats finite, because the stored form is JSON. Arrays are not
storable on their own, and neither is `null` — a missing key already reads as
that.

## The shared bucket, and reading another automation

An automation gets its own scope by default. An attribute on the constructor
parameter chooses another:

```php
use Stewart\Contracts\Store\GlobalStore;
use Stewart\Contracts\Store\PeerStore;
use Stewart\Contracts\Store\ReadableStore;
use Stewart\Contracts\Store\Store;

public function __construct(
    private readonly Store $store,                               // this automation's own
    #[GlobalStore] private readonly Store $houseMode,            // the one bucket everything shares
    #[PeerStore('boiler')] private readonly ReadableStore $boiler, // another automation's keys, read-only
) {}
```

`#[GlobalStore]` is shared: every automation reads and writes it, so use it for
facts about the house rather than for one automation's bookkeeping. On a
`ReadableStore` parameter it arrives read-only. `#[PeerStore]` must be typed
`ReadableStore`, which is what keeps a key to a single writer — the automation
whose scope it is; typed `Store`, it fails that automation as it is built. A
peer naming an automation that does not exist fails the same way, and suggests
the closest ID.

A plain `ReadableStore` parameter is the automation's own scope, read-only.
The attributes live in contracts, so an automation needs nothing from the
runtime to pick a scope, and `services.php` needs nothing from it either.

An automation is never defined in `services.php`; a scalar it needs comes from
`options:` in `stewart.yaml`.

A service of your own that asks for a `Store` gets the shared bucket, because a
service is not an automation and has no scope of its own. A `ReadableStore`
gets it read-only, and `#[PeerStore]` works there too.

## When the store is not there

An operation that fails throws a `StoreException` coded `UNREACHABLE`, `REFUSED`,
`TIMED_OUT` or `RECENTLY_FAILED`. Inside a handler that is
reported like any other handler failure — the subscription stays armed — and
inside `initialize()` it stops that one automation.

After a timeout or a lost connection, a worker stops trying for
`persistence.recovery_interval`: every store call in that worker fails at once
with `RECENTLY_FAILED`, naming the reason, so an outage costs one timeout rather
than one per handler. The next call after that goes to the store again. Each
worker finds this out for itself; a worker that never touches the store never
waits on it. A refusal, such as incrementing a key that holds text, is the
caller's problem and never shuts the store off.

`stewart status` shows the store as down while any worker is holding off, and
the most recent failure any worker saw. Workers report it with their ping
answers, so it lags by up to one `ping_interval`.

Two things are worth knowing before counting on a write:

- `increment()` is atomic against other writers, but a dropped connection is
  retried, so it can apply twice. Count with it; do not bill with it.
- A write that throws one of those may still have been applied.

## What it looks like on the server

Values are stored as themselves, with nothing wrapped around them:

```
stewart:app:heating:opens       ->  42
stewart:app:heating:mode        ->  "away"
stewart:app:heating:thermostat  ->  {"target":21.5,"mode":"heat"}
stewart:global:house_mode       ->  "night"
```

So `valkey-cli --scan --pattern 'stewart:*'` is a readable answer to "what does
this automation think it knows", and the counter really is the server's own
`INCRBY`.
