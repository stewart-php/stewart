---
title: 'Architecture'
description: 'Process model, packages, and the invariants that hold it together.'
sidebar:
  order: 1
---

For developers working on Stewart, or deciding whether its shape suits them.
It covers structure and the invariants that hold it together, not the API —
that is in the [guide](https://stewart-php.github.io/docs/guides/apps/).

---

## What it is

A framework for writing Home Assistant automations as ordinary PHP classes.
Stewart owns the connection to Home Assistant, keeps entity state, delivers
changes to your classes, and calls services back on their behalf.

![Context](diagrams/01-context.svg)

Two constraints from the environment shape everything below, and neither is
negotiable:

- **Home Assistant pushes.** Its WebSocket API is a stream of events with an
  `id`-correlated command channel alongside. Polling is not a viable
  substitute, so there is an event loop (Revolt) and everything is written
  against fibers.
- **One blocking call stalls a whole process.** `sleep()`, `curl_exec()`, PDO,
  `file_get_contents()` on a network path. This is the single largest source
  of user-inflicted breakage, and it is the reason automations do not all
  share one process.

---

## Process model

![Process model](diagrams/02-processes.svg)

A **broker** process owns everything shared: the WebSocket, the authoritative
state cache, the routing table, and the log stream. **Worker** processes run
the automations. They never talk to Home Assistant.

The split buys three things:

| | |
|---|---|
| **Crash containment** | A worker dying takes its apps down, not the daemon. |
| **Blocking containment** | An app that blocks stalls its own worker's loop, not every automation in the house. |
| **Cheap restarts** | The connection and state cache live in the broker, so restarting a worker costs no reconnect and no resync. A crashed worker is respawned with backoff, and quarantined if it will not stay up. |

Apps are spread round-robin over `min(4, cpu_count)` workers by default; an app
can pin itself to one with `worker:` in `stewart.yaml`. One process per app
would be the ideal, but at 15–30 MB RSS each it does not survive contact with a
Raspberry Pi.

An app passes through four stages, each with its own type:

| Stage | Type | Where |
|---|---|---|
| Discovered | `DiscoveredApp` | A class with `#[Automation]` found by the scan |
| Defined | `AppDefinition` | After `stewart.yaml` overrides: options and an optional worker pin |
| Placed | `WorkerSlot`, shipped to the worker as `WorkerApp` | `AppPlacement` groups definitions into a `WorkerSlotCollection` |
| Running | `AppSlot` with an `AppState` | Inside a worker; a failure is tagged with the `AppFailurePhase` it happened in |

### The cost

Every worker holds a **full cache** of entity state, which means every worker
must receive **every** state change, subscribed or not — a state cache can only stay
fresh if it sees everything. Broker-side routing reduces the work a worker does
per event; it can never reduce the number of events it receives.

That is the deliberate trade for `getState()` being a synchronous hash lookup
rather than an await. The same cache supplies each change's `from`, so the broker
sends only the new state.

---

## Packages

![Packages](diagrams/03-packages.svg)

| Package | Depends on (Stewart packages only) |
|---|---|
| `contracts` | nothing |
| `sun` | `contracts` |
| `support` | `contracts` |
| `client` | `contracts`, `support` |
| `store` | `contracts`, `support` |
| `store-redis` | `contracts`, `store`, `support` |
| `runtime` | `contracts`, `client`, `store`, `sun`, `support` |
| `mqtt` | `contracts`, `runtime`, `support` |
| `codegen` (optional) | `contracts`, `client`, `runtime`, `support` |
| `testing` (development only) | `contracts`, `store`, `support` |

**`stewart-php/contracts`** is the sink of the dependency graph, holds only the
API apps see, and depends on PHP alone. That is a rule, not a
coincidence: it is what allows a test double to exist without an event loop or
an HTTP stack, and what lets the generated entity classes — thousands of methods
typed against `HaContext` — exist without dragging in `amphp/parallel`. Time
reaches the operators through the `Timers` port, never through Revolt directly;
deadlines and waits are the runtime's own `Deadlines` port.

**`stewart-php/support`** holds the helpers the framework packages share and
apps never see: the stream operators behind `EventStream`, the schedule
implementations behind `Scheduler` (with the cron parser), wire payloads, the
indexed registry, JSON shape checks and encoding, URL redaction and "did you mean"
suggestions. Every class is `@internal`, and apps and generated code may not
depend on it. The few internals left in contracts are held by app-facing types,
and `InternalSurfaceTest` keeps that list closed.

**`stewart-php/client`** is a Home Assistant WebSocket client that knows
nothing about brokers, workers or apps. Usable on its own, and what code
generation reads the instance through.

**`stewart-php/runtime`** is the broker, the workers, and the protocol between
them. IPC lives under the `Stewart\Runtime\Ipc\*` namespace root so extracting
it later is a directory move that preserves every class name.
Every process is wired by Symfony DI: `Runtime\Kernel` compiles a console, a
broker or a worker profile from `packages/runtime/config/`, with the values only
known at run time (config, `Bootstrap`, transport) as synthetic services and
named bindings. Messages over the channel go to one handler class each
(`Broker\Message`, `Worker\Message`).
The control socket lives under `Stewart\Runtime\Control`: `Protocol` is its
wire schema, a leaf the broker reports into; `Server` answers it and `Client`
is what `stewart status` reads it with. The phases both wires name live in
`Stewart\Runtime\Lifecycle`.

**`stewart-php/sun`** computes sunrise, sunset, twilights, golden and blue hour
and the sun's position from a `GeoLocation`, with the NOAA equations and Astral's
refraction so its times agree with Home Assistant's. It depends on contracts
alone; apps reach it as the `SunCalendar` contract, located where Home Assistant
says it is when the broker connects.

**`stewart-php/store`** holds the scoping and encoding of persistent values, and
**`stewart-php/store-redis`** the one backend shipped today. The broker's
`StoreBackendRegistry` collects every factory tagged `stewart.store_backend`,
picks one by the scheme of `persistence.url`, and sends its class to each worker
in `Bootstrap`.

### Adding a second backend

1. Implement `Stewart\Store\StoreBackend` and a `StoreBackendFactory` with a
   no-argument constructor, in a package that requires `stewart-php/store`.
   Throw `StoreException` with the reason `TimedOut` or `Unreachable` when the
   store cannot be reached, fail fast with `RecentlyFailed` for the
   `StoreTiming`'s `recoveryInterval` after that, and report it through
   `health()`: the rest are the caller's problem.
2. Ship a `config/services.php` that registers the factory with the
   `stewart.store_backend` tag, and add the package to the broker profile's
   optional packages in `ContainerProfile`.
3. `composer require` it and set `persistence.url` to `sqlite://…`.

**`stewart-php/mqtt`** is an MQTT 3.1.1 client over amphp. The broker holds the
one connection; workers reach it through the `Mqtt` contract. It plugs into the
runtime the same way a store backend does, through its `config/services.php`.

**`stewart-php/testing`** holds `AppHarness` and the recording fakes apps are
tested with. It builds apps the way a worker does, against contracts and the
store, without a runtime.

**`stewart-php/codegen`** turns `get_states` and `get_services` into the classes
under `App\Generated`. It depends on `contracts`, `client` and
`nette/php-generator`, and it is **optional**: the skeleton requires it so the
`generate` command exists on a server (`STEWART_BOOT_GENERATE`), but a project
without it runs unchanged, and the generated classes need `contracts` alone. Only its
`Console` namespace reaches the runtime, for configuration; the runtime never
names codegen; the console profile imports the package's `config/services.php`
when Composer reports it installed.

If you add a dependency to `contracts`, stop and reconsider.

---

## Configuration

One schema, `StewartConfigSchema`, declares the shape of `stewart.yaml` as a
`symfony/config` tree. It holds every default and every validation rule, and
four things come out of it: validation of the file, the mapping from
environment variable names onto config keys, the typed config, and the output
of `stewart config:reference`.

`ConfigLoader` merges up to three sources, in order:

| | |
|---|---|
| `stewart.yaml` | committed next to the automations; a value can read a variable with `${HA_TOKEN}` or `${HA_TOKEN:-fallback}` |
| `STEWART_*` in the environment | `STEWART_APPS__DEMO__WORKER` names `apps.demo.worker` |
| command-line options | `--log-level` |

Later sources win. `EnvironmentPlaceholders` resolves `${VAR}` in the file's
string values first. `EnvironmentOverlay` then turns `STEWART_*` into the
middle source by walking the built node tree rather than knowing any setting's
name. A segment resolves against the node's children. Under `apps` and
`apps.<id>.options`, where the keys are the user's own, it resolves against the
keys already in the file, so `STEWART_APPS__HALL_LIGHT__WORKER` finds
`hall-light` only when the file names it. An app option or a list such as
`codegen.include` takes inline YAML (`[hall, kitchen]`) and is replaced whole.

`ConfigLoader::process()` returns the validated array, which `config:dump`
prints, and `load()` builds `StewartConfig` from it with `fromArray`. The
config classes carry no defaults and re-check nothing. A rule about one value,
such as a store prefix, a namespace or a control address, runs in a
`validate()` closure on its node, so the error names its path.
`home_assistant` is optional in the tree; `run` and `generate` ask for it
through `StewartConfig::connection()`, so `status` and `config:dump` work
without it. Apps are discovered by `run`, not by the loader, and
`AppCatalog` merges them with the `apps:` overrides.

Unknown keys and unusable values are errors at boot, in the file and in the
environment alike, so a typo fails loudly instead of doing nothing.

### Traps in the config layer

Things that look like dead code or an oversight, and are not:

- `StewartConfigSchema::ROOT` and `EnvironmentOverlay::PREFIX` are kept in step
  **by hand** — a constant expression cannot uppercase.
- The coercion `match` in `EnvironmentOverlay` is **order-sensitive**: the
  integer, boolean and enum nodes all extend `ScalarNode`, and `EnumNode` must
  pass through as the string it arrived as so the schema reports its own
  permissible values.
- Option values round-trip through a string compare rather than
  `is_numeric()`, so `"007"` stays a string while `180` becomes an int. An
  entity ID or a zip code depends on this.
- A duration is a scalar node holding text such as `5s`, checked by
  `Duration::parse()` in the node's validation and parsed again by `fromArray`,
  which turns `off` into `null`.
- The list nodes use `performNoDeepMerging()`. Without it, a list from the
  environment would be appended to the file's list instead of replacing it.
- Some **absences are deliberate**. `home_assistant` is required as a whole
  rather than defaulted, so a missing section reports the missing URL. Nothing
  validates app IDs in the schema, because the schema cannot see what is on
  disk — which IDs exist is `AppCatalog`'s question, and `run` logs a warning for an
  entry that names none of them.
- `normalizeKeys(false)` on the app node is load-bearing. Symfony rewrites `-`
  to `_` in keys by default, which would quietly turn the app `hall-light` into
  `hall_light` and orphan its logs, its state and its enabled flag.
- An app's `options` are spread into its constructor as **named arguments**, so
  they are ordinary typed parameters and a key matching no parameter fails
  loudly rather than being ignored.
- Docker Compose writes an unset `VAR: "${VAR:-}"` passthrough as an empty
  string rather than leaving it unset, so empty has to mean absent.

---

## The API surface

![Contracts](diagrams/04-contracts.svg)

An app is a class carrying `#[Automation(id: '…')]` and implementing `App`.
`initialize()` registers subscriptions; an app that also implements
`Disposable` releases in `dispose()` anything that outlives them. It is found by scanning `apps/`, so adding one means writing a
class rather than also editing YAML, and its constructor may take an `HaContext`,
anything `options:` supplies, and any service registered in `services.php` —
all by type, in any order.

The worker container autowires contract types only: `HaContext`,
`LoggerInterface`, `Scheduler`, `Timers`, `Clock`, `SunCalendar`, `Store`,
`ReadableStore`, `Mqtt`, `EntityExposure`, `RegistryEditor` and the generated
roots. Everything else the runtime registers sits under an id
starting with a dot, which autowiring never matches, so the transport, the
state cache and the dispatcher are not reachable from user wiring. A store scope
other than the automation's own is chosen with `#[GlobalStore]` or
`#[PeerStore('id')]` on the parameter, both in contracts; `services.php` never
imports `Stewart\Runtime` or `Stewart\Store`, and never defines an automation.
A worker compiles its container once; if that fails, every automation in the
worker fails with Symfony's message.

Two things about this shape are load-bearing:

**Subscriptions return a stream, not a bare callback.** The operator layer
(`filter`, `debounce`, `whenChangedTo`) slots between `watchStateChanges(...)`
and `->subscribe(...)`, so a new operator needs no new API. A
callback-registration API would need a parallel API for every way of shaping
events.

**Selectors are value objects, never closures.** An event or topic selector
crosses to the broker and routes there; every predicate and operator runs
worker-side on events that already arrived. This is forced rather than chosen —
the channel carries JSON frames, and a closure has no JSON form.
The worst case is an app subscribing to everything and taking the firehose,
which is one worker's problem.

---

## Invariants

The things that are easy to break by accident, and what breaks when you do.

**`HaContext::getState()` must never suspend.** Both implementations read a local
cache. If one starts awaiting, apps that read state in a loop change behavior,
and code that works in a worker breaks in-process. The interface would be lying
about itself. `getHistory()` is a request to Home Assistant, not a state read;
it suspends like `callService()`.

**The state cache is mutated, never replaced.** A worker hands the same
`StateCache` object to every app's context when it constructs them — which
happens *before* the snapshot arrives. Swapping in a new instance on load
leaves every app reading an empty world, which shows up as a missing entity, not
as a wiring bug. Guarded at the worker level by `AppContainerBuilderTest`. The worker container holds exactly one
`StateCache`; the app container never defines one and reaches the contexts
through `AppRuntimeServices`, so there is no definition it could build a second
one from.

**Containers are consulted at boot and never again.** A kernel resolves its
root; a worker's app container builds the apps and their services; then both
go out of scope. This is what allows a container
inside a process running an event loop at all. Resolving a
service per event would void it.

**App discovery is sorted by ID.** `AppPlacement::planWorkerSlots()` spreads apps
round-robin in array order, so an order left to the filesystem would move an
automation between workers from one boot to the next.

**Apps are constructed at `Bootstrap` but initialized only after
`StateSnapshot`.** Reading state inside `initialize()` is normal and must work.
Both the schedule registry and the subscription queues stay dormant per app
until that app's `initialize()` returns — otherwise a schedule armed in a
constructor would fire against an empty world, and an event matched during
initialization would reach a half-built app. The IPC reader starts *before*
construction, so a constructor may await a service call; initialization waits on
a barrier for construction to finish, whichever arrives first. An app that does
not return from `initialize()` within `supervision.initialize_timeout` is
dropped and the rest start without it; the broker's
`supervision.ready_timeout` is the backstop for a worker that never reports
ready at all.

**A schedule recomputes its next occurrence from the clock, never by adding to
the last one.** Revolt's timers run on a monotonic clock that stops while the
host is suspended and ignores an NTP step, so an armed timer is a hint rather
than a promise; a wall-time wait is taken in hops of at most 60 seconds, each
re-reading the wall clock. Accumulating instead would drift, and would replay
every occurrence a suspend went past. Sun schedules are wall-clock schedules
like any other: the next sunrise is asked of the `SunCalendar` each time.

**Every service call and history request gets a reply.** Including on failure, and
including when the connection dropped. The worker's fiber is suspended on that
reply; a missing one is not a lost message but a permanently stuck automation
with nothing in the logs. The worker-side call timeout is the backstop.

**A worker returns from its entrypoint; it never calls `exit()`.**
`amphp/parallel` writes the result channel only after the callable returns, so
exiting turns a clean stop into a `ContextException` in the broker.

**The broker holds a strong reference to every `ProcessContext`.**
`ProcessContext::__destruct()` calls `close()`, which kills the process. A
handle that falls out of scope silently kills its worker.

**Child stdout and stderr must be drained.** `ProcessContextFactory` does not
pipe them, unlike `DefaultContextFactory`. Undrained, the child eventually
blocks forever writing to a full OS pipe buffer — a hang with nothing in the
logs.

**Seeding, re-encoding and broadcasting a snapshot contain no suspension
point.** `WorkerHandle::send()` only appends to a buffer and kicks an `async()`
drain, so no fiber can observe a half-rebuilt cache. This is what makes the
reconnect and worker-restart interleavings impossible rather than merely
handled. An `await` inserted into that block reopens all of them.

**The broker subscribes before it seeds.** Calling `get_states` first loses
every change between its response and the subscription ack, permanently — the
cache stays wrong until that entity moves again. `connectSubscribeAndSeed()` buffers by
entity id in between and drains after the seed.

**A dead worker's subscriptions are deregistered synchronously, before any
respawn is scheduled.** `LocalDispatcher` mints subscription ids from a counter
that restarts at 0 in every process, so a restarted worker 0 re-mints exactly
the ids the broker just removed. Deregistering late, or asynchronously, routes
an event with a stale id onto a new and unrelated handler.

**A worker slot has exactly one phase.** `WorkerSlotState` holds it
(`Stopped`, `Spawning`, `Live`, `RestartScheduled` or `Quarantined`, the
control protocol's `WorkerPhase`)
with the handle and the timers that phase owns, and refuses a transition from
the wrong phase. Leaving a phase cancels its timers, so a restart or readiness
deadline cannot fire against a slot that has moved on. `WorkerMessageReader` reads each
channel, `WorkerWatchdog` probes, and `SnapshotAssembler` reports; the pool
only drives the transitions.

**A service call's reply goes to the handle captured in its closure, never to a
lookup.** Correlation ids also restart per process, so resolving through the
worker map would let a dead worker's `0:7` complete a new worker's unrelated
`0:7`. `HaCallSlots` counts in-flight calls per handle for the same
reason, and never routes by them.

**`WorkerHandle`'s `ProcessContext` generics stay `object`.** `Channel`'s send
type is invariant, so narrowing them to the message interfaces would stop the
values reaching the transport. It reads like a missed opportunity and is not.

**The IPC vocabulary is its own.** `Bootstrap` carries `WorkerApp`,
`WorkerSettings` and `StoreSettings`, not the config value objects, and every
message crosses as a tagged JSON frame with a protocol version. Adding a config property is
not a wire change. Renaming a message property is.

---

## Runtime characteristics

Measured on the target container (PHP 8.4, Alpine), 2000 synthetic entities:

| | |
|---|---|
| `json_encode()` the state graph (cached, paid once) | ~1.7 ms, 0.6 MB |
| Baseline worker PHP heap at boot | ~4 MB (`memory_get_usage(true)`; RSS is higher) |
| Building a worker's container | ~76 ms fixed, plus ~0.5 ms per automation, no measurable heap |
| A worker with no automations, `Bootstrap` to stop (runtime and app containers) | ~115 ms, 6 MB peak |

The broker encodes the snapshot once and ships the string to every worker, so its
cost does not grow with the number of workers. The snapshot travels as one
message, which is why `home_assistant.message_size_limit` defaults to 64 MB.

The container cost is loading the component, not compiling the graph, which is
why it hardly moves between 2 and 30 automations. It is paid once per worker
process, against a process spawn that already costs more.

Worth measuring next: **events per second** against a real instance, because it
multiplies by worker count.

---

## Extension seams

Deliberately unbuilt, with the shape they would take:

| Feature | Where it goes |
|---|---|
| Restarting one app | `WorkerPool` respawns a whole worker; one app alone needs scopes that can be re-activated, which `ScopeLifecycle` does not do today. |
| Remote workers | Behind the `Transport` interface, which is three methods wide for this reason. |
| A second store backend | See [Adding a second backend](#adding-a-second-backend). |
| Area, device, floor and label classes | More registry commands and a second naming scheme inside `codegen`. Today only their IDs are completed, through `.phpstorm.meta.php`. |

---

## Design decisions

Why some things are the way they are. Each was a choice between workable options.

### Generated entities are looked up by ID

`$entities->light->getEntity('light.hall')`, not a property per entity.

- An ID written in full is searchable: a search for `light.hall` finds every use.
- An ID from an app's options is a plain string, and works the same way.
- Only IDs the last `stewart generate` saw are accepted; others throw `IdentifierError::EntityNotGenerated`. There is
  no static check on the ID, because a literal-union type made every string from config a PHPStan error. Completion
  comes from `.phpstorm.meta.php` instead.

### Sun times are computed locally

`stewart-php/sun` implements the NOAA equations with Astral's refraction and horizon dip, so times agree with Home
Assistant's sun integration within seconds.

- Sun schedules keep running while Home Assistant is unreachable; a `sun` trigger would not.
- `SunEventSchedule` is a wall-clock schedule, so missed runs and clock steps behave as for every other schedule.
- The location comes from `get_config` when the broker connects and travels in `Bootstrap`; a change applies on
  restart. Without one, workers get `UnlocatedSunCalendar`, and every query throws `SunError::LocationUnknown`.

### The registry is mirrored to every worker

Area, floor and label filters run on every state change, so they must not ask Home Assistant.

- The broker reads the five registries on every connect, and again after a one-second quiet period following any
  `*_registry_updated` event. A failed read keeps the previous registry.
- A new worker gets `Bootstrap`, `RegistrySnapshot`, then `StateSnapshot`. `RegistryCache` keeps the highest
  revision, and lookups never suspend.
- Placement follows Home Assistant: the entity's area, else its device's; labels of the entity, device and area.
  Moving an entity changes no state, so a running watch sees the move only on the entity's next change.

### Pausing an app is a gate

A paused app stays constructed and initialized. The worker drops its deliveries and skips its schedule runs, with no
catch-up on resume; a handler already running finishes.

- The runtime cannot dispose and re-initialize an app in place (`ScopeLifecycle` never re-activates a released
  scope), so stopping it was not an option.
- The broker's `AppPauseRegistry` is the source of truth, changed only through `AppPauseService`, which records the
  source (`config`, `control`, `http`). The worker enforces it, because it owns schedules and queued deliveries.
- Overrides persist under `<prefix>:runtime:app-pause:<id>` and beat `apps.<id>.paused`. A failed load at start stops
  the daemon, so an app paused on purpose never runs by surprise.

### A Git deployment switches releases by restarting in place

The broker loads app classes and spawns workers from its own `vendor/`, so new code needs a new PHP process.

- The entrypoint shell owns git, Composer, the release layout (`/app/releases/<sha>`, `current`, `next`) and the
  restart loop, so credentials stay in one place. The broker owns the poll timer, validation and status.
- A new commit must pass `stewart check` in a subprocess. A rejected commit is remembered and never tried again.
- Switching is a graceful stop with exit code 75; the entrypoint swaps `next` over `current` and starts PHP again.
  `tini -g` is not used: a group signal would reach the workers and skip the broker's graceful shutdown.

---

## Next

- [Channel protocol](protocol.md) — the message set and the sequences that use it.
