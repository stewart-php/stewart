---
title: 'Code walkthrough'
description: 'The execution path in the order it runs, from bin/stewart to an app handling an event.'
sidebar:
  order: 2
---

A reading of the execution path, in the order the code runs, from
`bin/stewart` to an automation handling an event.

Where [`architecture.md`](architecture.md) describes the shape and the
invariants, this follows the actual classes and explains why each does what it
does.

Each section ends with observations — things worth a second look. They are a
review snapshot taken 2026-09-16 against the first pass, not a defect list.

```
bin/stewart  ->  BrokerContainer  ->  Console app
                                        +- RunCommand -> ConfigLoader -> Broker
                                                                          +- HaClient (websocket to HA)
                                                                          +- worker processes -> WorkerRuntime -> your Apps
```

| | |
|---|---|
| [1. Entrypoint](#1-entrypoint-binstewart) | `bin/stewart` |
| [2. The container](#2-brokercontainerbuild-the-compile-time--runtime-split) | `BrokerContainer`, `Services` |
| [3. Configuration](#3-configuration-file-environment-command-line) | `ConfigLoader`, `StewartConfigSchema`, `EnvironmentOverlay` |
| [4. App discovery](#4-app-discovery-how-a-file-becomes-a-running-automation) | `AppDiscovery`, `AppCatalog`, `#[Automation]` |
| [5. Broker boot](#5-brokerrun-the-boot-sequence) | `Broker`, `WorkerHandle` |
| [6. The routing loop](#6-the-routing-loop) | `SubscriptionRegistry`, selectors |
| [7. The channel protocol](#7-the-channel-protocol) | `Transport`, the twenty messages, ADR 3 |
| [8. The worker process](#8-the-worker-process) | `worker.php`, `WorkerContainer`, `WorkerRuntime` |
| [9. Context and dispatch](#9-hacontext-and-the-dispatch-layer) | `WorkerHaContext`, `LocalDispatcher`, `PendingCalls` |
| [10. The client](#10-the-client-package) | `HaClient`, `HaConnection`, `MessageCorrelator` |
| [11. The rest](#11-the-rest) | in-process context, logging, value objects, `bin/` |
| [Observations](#consolidated-observations) | the whole list, grouped |

---

## 1. Entrypoint: `bin/stewart`

Forty-odd lines that deliberately do almost nothing. Four things happen, in
order.

### The autoloader, captured as a value

```php
$loader = require $autoload;
```

Most PHP entrypoints write `require 'vendor/autoload.php';` and throw the
return value away. Here it is kept, because Composer's `ClassLoader` is the
only thing that knows the PSR-4 prefix map — and that map is what app
discovery later uses to turn a *file path* into a *class name*. It is handed
straight into the container. That is the one reason this variable exists.

### `.env`, optional

```php
new Dotenv()->usePutenv()->load($dotenv);
```

PHP 8.4 syntax: `new X()->method()` without parentheses around the `new`.

`usePutenv()` matters. It pushes values into the real process environment, not
just `$_ENV`. Workers are spawned as child processes later and children inherit
the environment, so the token reaches a worker without anyone serializing it
across the channel.

A missing `.env` is fine. A malformed one is a hard exit — a broken secrets
file should not silently degrade into "connect anonymously".

### The container

```php
$services = BrokerContainer::build($loader->getPrefixesPsr4(), dirname(__DIR__));
```

Two arguments: the PSR-4 map for discovery, and the project root for locating
`apps/`, `stewart.yaml` and `services.php`. Nothing else, because config has
not been read at this point.

### Console, with a command *loader*

```php
$application->setCommandLoader($services->typed('console.command_loader', CommandLoaderInterface::class));
$application->setDefaultCommand('run');
```

A `CommandLoaderInterface` is lazy: Symfony reads the `#[AsCommand]`
attributes at compile time to learn command *names*, and only instantiates the
one actually invoked. The stated reason — `config:reference` must work when
there is no `stewart.yaml` — is the giveaway. `RunCommand`'s dependencies would
blow up on a missing or invalid config, and with an eager command list that
failure would take down `config:reference` too, which is exactly the command
you would run to find out what the config should look like.

`setDefaultCommand('run')` means bare `bin/stewart` starts the daemon.

### Observations

`exit($application->run())` — the broker's event loop lives *inside*
`RunCommand::execute()`. There is no `Loop::run()` at this level. That is an
amphp v3 / Revolt property: the loop starts implicitly at the first suspension
point and unwinds back out to this `exit`.

---

## 2. `BrokerContainer::build()`: the compile-time / runtime split

`packages/runtime/src/Runtime/Container/BrokerContainer.php` — one static
method, and the whole design hinges on one problem.

### The chicken-and-egg

The container needs a logger and a connection config. Both come from
`stewart.yaml`. But *which* yaml file comes from `--config`, a CLI option — and
Symfony Console cannot parse options until the `Application` exists, which
needs the command loader, which needs the container. Config cannot be read
before the container is built.

```php
foreach ([Services::class, LoggerInterface::class, StewartConfig::class, ConnectionConfig::class] as $id) {
    $container->register($id)->setSynthetic(true)->setPublic(true);
}
```

**Synthetic** means: this ID is real, the type is real, but I will never
construct it — you hand me the instance after compile. Four services declared
as holes.

`setPublic(true)` is what makes `get()`/`set()` legal from outside after
compilation. Symfony makes services private by default and inlines them away at
compile time; these four must survive as addressable IDs.

The holes are filled in `RunCommand::execute()`:

```php
$this->services->set(LoggerInterface::class, $logger);
$this->services->set(StewartConfig::class, $config);
$this->services->set(ConnectionConfig::class, $loaded->connection);

$broker = $this->services->get(Broker::class);   // first construction of anything real
```

That last line is the seam. Everything before it is wiring; everything after it
is a running system. And because `HaClient` takes `ConnectionConfig` and
`LoggerInterface` by reference, it is *physically incapable* of being
constructed before those `set()` calls ran. Not laziness by convention —
laziness enforced by the graph.

### Three kinds of argument

The mix is deliberate:

```php
->setArguments([$psr4, $projectDir . '/apps'])       // literal values from bin/stewart
->setArguments([..., '%stewart.services_file%'])     // container parameter
->setArguments([new Reference(HaClient::class), ...]) // service reference
```

`AppDiscovery` gets the PSR-4 map as a *literal* — the value the entrypoint
went out of its way to capture. It cannot be a parameter or a reference; it
exists only because `bin/stewart` held onto Composer's return value.

`EnvironmentOverlay` uses a static factory `fromGlobals()` rather than a
constructor. That is a snapshot boundary: the environment is read once, at
build, not consulted repeatedly later.

### The console-command magic

```php
$container->registerForAutoconfiguration(Command::class)->addTag('console.command');
$container->addCompilerPass(new AddConsoleCommandPass());
```

`AddConsoleCommandPass` is a stock Symfony pass. It scans every
`console.command`-tagged service, reads its `#[AsCommand]` attribute for the
name, and synthesizes a `console.command_loader` service — a name-to-factory
map. That is precisely the service the entrypoint fetches, and what makes the
lazy-loading claim above true in practice.

The three commands are registered explicitly by class rather than by directory
scan. Small, closed set; no glob needed.

### The self-reference

```php
$container->compile();

$services = new Services($container);
$container->set(Services::class, $services);
```

`Services` wraps the container and is itself one of the four synthetics, so it
can be injected — which is how `RunCommand` gets the handle it needs to close
the other three holes. A wrapper that is also a citizen of what it wraps.

### `Services` itself

Its docblock states the motivation plainly: `Container::get()` returns
`?object`, and at PHPStan max that null infects every call site. `narrow()`
does the null-and-instanceof check once and throws a sentence instead of a
`TypeError` three frames deep.

Two accessors, not one:

- `get(string $id)` — `@param class-string<T>`, returns `T`. For services
  registered under their own class name.
- `typed(string $id, string $type)` — for string IDs like
  `'console.command_loader'`, where the ID and the type differ.

### Observations

- `Services::container()` leaks the raw container. Nothing in the runtime
  needs it; worth checking whether it earns its place or is an escape hatch
  that will get abused.
- The container is compiled fresh on every invocation, with no dump or cache.
  Irrelevant for a daemon that starts once, but `bin/bench-container.php`
  exists, which suggests this cost was measured for *workers*, where it is paid
  per process.

---

## 3. Configuration: file, environment, command line

Four classes in `packages/runtime/src/Runtime/Config/`. The pipeline is
`ConfigLoader::load()`, and it is short because the work is pushed into the two
collaborators.

### The pipeline

```php
$file       = self::parse($path);                                    // stewart.yaml -> array
$discovered = $this->apps->discover();                               // scan apps/ for #[Automation]
$tree       = $this->configuration->tree();                          // the schema, as a NodeInterface
$override   = $this->environment->apply($tree, $file, $discovered->ids());

$configs = [$file, $override->values];
if ($overrides !== []) { $configs[] = $overrides; }

$processed = new Processor()->process($tree, $configs);
```

Precedence is just **array order** — Symfony's `Processor` merges
left-to-right, last wins. File, then environment, then `--log-level`. No
precedence engine, no priority integers.

`discover()` runs *before* the environment is read, and its result is passed
in. That ordering is load-bearing; see below.

### `StewartConfigSchema` — the schema

Standard Symfony Config `TreeBuilder`, but several declarations are defensive
against specific footguns.

**`normalizeKeys(false)` on `apps`.** Symfony's default key normalization turns
`hall-light:` into `hall_light`, which would then match no automation ID.

**Unsetting an explicit `worker: ~`.** `integerNode('worker')->defaultNull()`
— but an integer node *cannot hold* null, so writing `worker: ~` to mean "not
pinned" would be a validation error. A `beforeNormalization` hook deletes the
key entirely so the default applies.

**`isRequired()` on the whole `home_assistant` section.** Making the *section*
required rather than only its children means the error names `url` instead of
shrugging at an absent parent.

**URL normalization delegated to the VO.**

```php
->beforeNormalization()->ifString()
    ->then(static fn (string $url): string => ConnectionConfig::normalizeUrl($url))
```

By the time anything reads `home_assistant.url` it is already a
`ws://.../api/websocket`. The parsing rule lives in the client package's own
value object; the schema just invokes it. The client stays standalone-usable
and there is exactly one implementation of that rule.

A schema can validate shape but not that `apps.demo` names a real automation —
that needs reflection. Deferred, deliberately, to `AppCatalog`.

### `EnvironmentOverlay` — the interesting one

The naive way to map `STEWART_APPS__DEMO__OPTIONS__WATCH` to config is a
hand-maintained lookup table. This instead **walks the variable's segments down
the config tree in lockstep**.

At each segment it branches on the node type it is standing on:

| node | what it does |
|---|---|
| `PrototypedArrayNode` (`apps`) | keys are the user's, not the schema's — match against known keys |
| `ArrayNode` | children are known — match, or throw with a suggestion |
| `VariableNode` not `ScalarNode` (`options`) | stop; nest the remaining segments, infer the value |
| anything else | "goes deeper than the configuration does: X is a value, not a section" |

The prototyped-node case is where `discover()` earns its position in the
pipeline. Apps are found by *scanning*, so most have no entry in
`stewart.yaml` to match against. Without the discovered IDs,
`STEWART_APPS__HALL_LIGHT__WORKER` would fall through to `strtolower()` and
land under key `hall_light` — silently configuring an app that does not exist,
while `hall-light` runs unconfigured. `withKnownApps()` seeds an empty entry for
every discovered ID so key matching can find it.

```php
private static function comparable(string $key): string
{
    return strtolower(strtr($key, '-', '_'));
}
```

`HALL_LIGHT`, `hall-light` and `hall_light` are all the same key.

**Order of the `match(true)` in `coerce()`.** `IntegerNode`, `FloatNode`,
`BooleanNode` and `EnumNode` are all `ScalarNode` subclasses. Put `ScalarNode`
first and every typed node silently receives a string.

**`infer()` for free-form option values** — two round-trip tricks:

```php
// Round-tripping keeps "007" a string while 180 becomes an int.
if ($value === (string) (int) $value) { return (int) $value; }

// is_numeric() alone would turn "007" into 7.0.
if ($value !== '' && is_numeric($value) && $value === (string) (float) $value) { return (float) $value; }
```

`"007"` becomes `7` becomes `"7"`, which is not `"007"`, so it stays a string.
Zip codes, device IDs and version numbers survive.

**Empty means absent.** Docker Compose passthrough of an unset variable yields
`""`. Treating that as set-to-empty would override the file with nothing.

**Typo suggestions** — levenshtein with threshold `max(2, strlen/3)`,
reconstructing a full variable name for the hint. That is the "a key that
matches nothing is an error rather than a shrug" promise, implemented.

### Provenance

`ConfigProvenance::trace()` recurses the **processed** result — not the file —
so defaults show up too. Per leaf:

```php
$sources[$dotted] = $environment[$dotted] ?? (self::present($file, $path) ? $fileName : 'default');
```

Environment variable name, else the filename, else `'default'`.
`ConfigDumpCommand` then flattens and prints it, masking anything matching
`/token|secret|password|passwd|api_?key/i` — deliberately broad, because
`apps.*.options` is free-form and will hold users' own credentials.

### `StewartConfig` — the runtime value object

```php
return max(1, min($configured, max(1, \count($this->apps))));
```

Never more workers than apps, always at least one.

```php
$output = @shell_exec('nproc 2>/dev/null');
```

`CpuCount` asks `nproc`, which honors a cpuset but not a `--cpus` quota; the
cap of four and the `workers` setting cover the rest.

`assignApps()` distributes round-robin with pins honored, and `$next++` only
advances in the unpinned branch, so pinning does not perturb the rotation of
the others.

### Error message philosophy

Two distinct voices, used deliberately throughout the codebase:

- **User error** says what to fix: *"Config file X not found. Stewart ships a
  commented stewart.yaml; copy it next to your apps to get started."*
- **Internal invariant** says it is not your fault: *"The schema and the loader
  disagree, which is a bug in Stewart."* Same phrasing as `Services::narrow()`.
  After `Processor` has validated the tree, these can only fire if the schema
  and the reader drifted apart.

### Observations

- **Two unused methods.** `ConfigLoader::fromGlobals()` and
  `Services::container()` have zero call sites in `packages/`, `apps/`,
  `tests/` or `bin/`.
- **`assignApps()` wraps pins silently.** `$app->worker % $workerCount` — pin
  to worker 5 with 2 workers and you get worker 1, no warning. Out of character
  for a file that works this hard to turn typos into errors. Also, two apps
  both pinned to worker 0 still spawns an empty worker 1.
- **`RENAMED`** is a migration shim for `HA_URL`/`HA_TOKEN` — history-in-code
  with no removal trigger.

---

## 4. App discovery: how a file becomes a running automation

### The two contracts

Both live in `packages/contracts/`, the package that depends on nothing but
PHP.

```php
interface App
{
    /** Register subscriptions and schedules. */
    public function initialize(): void;

    /** Release anything that outlives a subscription - open handles, external timers, buffered work. */
    public function dispose(): void;
}
```

That `dispose()` docblock is a contract statement, not a description. "Anything
that outlives a subscription" implies subscriptions themselves are the
runtime's problem. You never unsubscribe by hand.

```php
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Automation
{
    /** @param non-empty-string $id identity that outlives a class rename */
    public function __construct(public string $id) {}
}
```

One field, enforced twice — statically by PHPStan and at runtime in `check()`.

### The scan

No `apps/` directory returns an empty result, not an exception. That is what
lets the "No automations to run; Stewart will connect and do nothing" warning
exist as a warning.

Then per file: path-to-class -> `class_exists()` -> reflect -> classify.

### `psr4Root()` — inverting Composer's map

Composer gives you namespace-to-directories. Discovery needs the inverse.

```php
if ($candidate !== $root && !str_starts_with($root, $candidate . \DIRECTORY_SEPARATOR)) {
    continue;
}

// Longest wins: a deeper prefix is the more specific claim on the same file.
if ($base === null || \strlen($candidate) > \strlen($base)) {
```

The `. DIRECTORY_SEPARATOR` on the prefix check stops `/project/app` matching
`/project/apps`. And **longest wins** mirrors Composer's own resolution, so
discovery and the autoloader cannot disagree.

Failing to find one is a hard error naming the fix: add it to the `autoload`
section of `composer.json`, then run `make install`.

### `classFor()` — trusting PSR-4 rather than parsing

```php
$relative = substr($file, \strlen($base) + 1, -\strlen('.php'));
$class    = $prefix . strtr($relative, \DIRECTORY_SEPARATOR, '\\');
```

Pure string surgery. No tokenizer, no reading the file for its `namespace`
declaration — it asserts the PSR-4 rule and lets `class_exists()` adjudicate.

### The classification asymmetry

| | verdict |
|---|---|
| Implements `App`, **no** attribute | collected into `unmarked` -> **warning** |
| **Has** attribute, does not implement `App` | **hard error** |
| **Has** attribute, not instantiable | **hard error** |
| **Has** attribute, empty ID | **hard error** |

The attribute is a *statement of intent*. Having made it, failing the contract
is a mistake worth stopping for. Not having made it is ambiguous — a base
class, a helper, a work in progress — so it is a warning.

`isInstantiable()` in the unmarked branch means an abstract `App` base class
stays quiet. Only concrete, runnable, unmarked classes get flagged.

`RunCommand` names the underlying risk explicitly:

```php
// An automation that is simply not found is the failure mode discovery
// introduces, and it looks exactly like nothing needing to happen.
```

That is the honest cost of attribute-based discovery: you forget the attribute
and the system does nothing, successfully. The `unmarked` bucket exists solely
to make that condition audible.

### Sorting twice, on purpose

Finder sorts by **path**; a subsequent `usort` re-sorts by **ID**. The second
sort is load-bearing: `assignApps()` distributes round-robin over this list, so
if ordering came from filenames, renaming `DemoApp.php` would silently move the
app to a different worker. Sorting by ID — the thing declared to outlive
renames — keeps assignment stable.

### `AppCatalog::merge()`

Where the schema's deferred check lands. Its error splits into two messages
using the same `comparable()` normalization as `EnvironmentOverlay`:

- Near-match: *"stewart.yaml configures an app "hall_light", but the automation
  is called "hall-light". The ID comes from its #[Automation] attribute and has
  to match exactly."*
- No match: lists every known ID, or — if there are none at all — *"is the
  class marked #[Automation]?"*, which is the exact question you would be
  asking.

### The direction of the merge

`merge()` iterates **what was discovered**, not what the file says. Discovery
is the source of truth for *which apps exist*; `stewart.yaml` only tunes them.
An app with no entry in the file still runs. The file literally cannot add an
app, only adjust or disable one.

### Observations

- **`AppDefinition::$enabled` is vestigial.** The only production construction
  site hardcodes `enabled: true`; the disabled case `continue`s before ever
  building one. Nothing anywhere reads `->enabled`.
- **`comparable()` is duplicated** in `AppCatalog` and `EnvironmentOverlay` —
  one rule about user-facing identifiers, two homes.
- **A namespace/path mismatch fails silently after executing the file.**
  `class_exists()` autoloads and *runs* the file, finds no such class, and
  continues with no word. The only discovery outcome with neither an error nor
  an `unmarked` entry.
- **Discovery runs in the broker only.** Workers receive `AppDefinition`
  objects over the channel rather than rescanning, so a worker restart cannot
  pick up new automations.

---

## 5. `Broker::run()`: the boot sequence

### Four calls and an await

```php
$this->connect();
$this->startWorkers();
$this->subscribe();
$this->installSignalHandlers();

$stopped->getFuture()->await();
```

The order is load-bearing in both directions:

- **`connect()` before `startWorkers()`** — each worker is handed a
  `StateSnapshot` the moment it spawns, so the cache has to be warm first. It
  is also fail-fast: a bad token kills the process before a single child
  exists.
- **`subscribe()` after `startWorkers()`** — no state-change event can arrive
  while the worker map is half-populated. The routing loop never sees a partial
  pool.

That last line is the whole daemon. It suspends the main fiber forever and
resolves only in `stop()`. It is also the suspension point that implicitly
starts the Revolt event loop, which is why there is no `Loop::run()` anywhere.

### `connect()`

Connect, pull the full state dump, seed, stamp a revision, capture the
timezone. The timezone is stored as a **string**, not a `DateTimeZone`, because
it has to cross a process boundary inside `Bootstrap`.

### `startWorkers()` — real processes

```php
$factory = new ProcessContextFactory(ipcHub: new LocalIpcHub());
$context = $factory->start([self::WORKER_ENTRYPOINT, (string) $workerId]);
```

`ProcessContext` is amphp/parallel's fork-and-exec — genuinely separate PHP
processes, not threads. `LocalIpcHub` sets up a Unix domain socket for the
channel. The entrypoint constant resolves relative to `__DIR__`, landing on
`packages/runtime/bin/worker.php`: the runtime package ships its own worker
binary rather than reaching into the project's `bin/`, which is what keeps
`stewart-php/runtime` installable as a library.

Partial failure is survivable — a worker that fails to start logs and
continues; only an empty pool is fatal.

**The lifetime anchor:**

```php
$state->spawned($handle, $this->readyDeadlineFor($handle));
```

`WorkerPool` keeps one `WorkerSlotState` per slot, and that call is where the
handle is anchored. It looks like bookkeeping and is not. PHP refcounting would
destruct the `ProcessContext` at the end of the iteration, and amphp's
destructor kills the child. The same call moves the slot from `Spawning` to
`Live` and closes whatever handle the slot held before.

**Order inside `start()`** — `pipeOutput()`, then `WorkerMessageReader::readMessagesInBackground()`, then
the listener's `send(Bootstrap)` and `send(StateSnapshot)`. The pump is reading
before the first byte goes out, so a worker that dies during bootstrap is still
observed. And because `send()` does not block, the loop spawns and bootstraps
all N workers concurrently; `WorkerReady` comes back asynchronously later.

`encodedSnapshot()` is the entire cache as one JSON string in one message. That
is why
`message_size_limit` defaults to 64 MB.

### `WorkerHandle::send()` — the backpressure policy

```php
if (\count($this->buffer) >= $this->bufferLimit) {
    // Drop the oldest: blocking here would let one slow app halt every automation.
    array_shift($this->buffer);
    ++$this->droppedEvents;
```

`send()` **never suspends**. It appends and kicks `flush()`, which drains in
its own fiber behind a `$flushing` guard — exactly one drain fiber per worker,
which is what preserves message ordering.

The comment carries the real argument. Awaiting the channel write would let one
wedged worker stall the broker's event loop, and the broker is the sole owner
of the Home Assistant connection. One blocking app would take down every
automation in the system. **Freshness over completeness**, chosen explicitly.

Log throttling — first drop, then every hundredth — because whatever causes
drops causes thousands, and a log line each would deepen the hole.
`terminate()` empties the buffer; a dead worker's queued messages are garbage.

### Two output paths, deliberately

`pipeOutput()` pipes the child's stdout and stderr into the parent's.
`Broker::onLogRecord()` folds a worker's PSR-3 records into the broker's own
logger with `app` and `worker` stamped into the context.

The split is intentional: **structured logging crosses the channel** so it
lands in one ordered stream; **raw process output is piped** so that a fatal
error, a PHP warning or a segfault message — things emitted when the channel is
already useless — still reach you.

### Signals

```php
EventLoop::unreference(EventLoop::onSignal($signal, function (): void {
    $this->stop('signal');
}));
```

`unreference()` is the subtle bit: a referenced watcher keeps the event loop
alive on its own. Without it the loop would refuse to exit even after `stop()`
resolved the future, and the process would hang after a clean shutdown.

`UnsupportedFeatureException` degrades to a warning. That is the `pcntl` check
`make doctor` performs.

### Observations

- **The Ping/Pong liveness probe is wired but never fired.** `Broker::ping()`
  has zero call sites — no `EventLoop::repeat`, no timer. So `onPong()` and its
  *"Worker event loop is lagging; is an app blocking?"* warning — the one
  diagnostic that would catch the most likely user error — can never trigger.
  `Broker::stats()` is likewise uncalled.
- **`snapshotRevision` is write-only.** Incremented, shipped in every
  `StateSnapshot`, logged on the worker side and never compared. An intentional
  seam for resync, but it reads as an unfinished check.
- **A failed worker start loses its apps silently.** The log names the worker
  index; `$apps` is in scope right there and is not mentioned. The operator
  learns a worker died, not that `hall-light` and `blinds` are now not running.

---

## 6. The routing loop

### `listenTo()` — one reader fiber per worker

```php
async(function () use ($handle): void {
    try {
        while (true) {
            $message = $transport->receive();
            if ($message === null) { break; }
            if ($message instanceof WorkerMessage) {
                $this->handleWorkerMessage($handle, $message);
            }
        }
        $reason = 'closed the channel';
    } catch (...) {
        $reason = $e->getMessage();
    }

    $this->onWorkerGone($handle, $reason);
})->ignore();
```

Symmetric with `WorkerHandle::flush()` — **one dedicated fiber per direction,
per worker**. Every exit path produces a `$reason` string and funnels into the
same `onWorkerGone()`. Clean EOF is distinguished from a crash by wording, so
you always learn *how* a worker left.

`->ignore()` tells amphp nobody will await this future, so an unhandled failure
is not an error. Used consistently for fire-and-forget fibers.

### `handleWorkerMessage()`

A `match(true)` over eight worker-to-broker message types, with
`default => null` — forward-compatible, so a newer worker sending something
this broker does not know is ignored rather than fatal.

### `onStateChanged()` — the central invariant

```php
$routes = $this->registry->route(SubscriptionKind::StateChange, $change->entityId);

foreach ($this->workers as $workerId => $handle) {
    if ($handle->isClosed()) { continue; }

    $handle->send(new StateChanged($change, $routes[$workerId] ?? []));
}
```

The loop iterates **the workers, not the routes**. Every worker receives every
state change. Routing decides only the second constructor argument — which
subscription IDs should fire on arrival.

Every
worker holds a **full** state cache, and a state cache that skipped unsubscribed
entities would rot, which would break `state()`'s promise of a local,
never-suspending read.

The contrast is three methods down in `onPublish()`, which iterates **routes**.
There is no state cache to maintain, so an uninterested worker gets nothing. Two
loops, two shapes, one reason.

### `SubscriptionRegistry` — the two-tier index

```php
$exactKey = $selector->exactMatch();

if ($exactKey !== null) {
    $this->exact[$this->cacheKey($kind, $exactKey)][$subscriptionId] = true;
} else {
    $this->patterns[$subscriptionId] = $registration;
}
```

Exact selectors go into a hash bucket. Patterns go into a list scanned linearly
per event. `matching()` combines both, then memoizes.

`exactMatch(): ?string` on `Selector` exists purely to enable that split. An
any-of selector returns its single member's exact match when the list has
exactly one element, so `stateChanges(['light.hall'])` still gets the fast path
instead of being punished for using a list.

This is where `Selector::from()`'s "cheapest selector that expresses it"
principle cashes out. Reading `light.hall` as an exact match rather than a
pattern is the difference between a hash lookup and a `preg_match` per event per
subscription. A regex is never inferred from a string; it has to be asked for
with `Selector::regex()`.

`cacheKey()` joins with `"\0"` — a NUL byte cannot occur in an entity ID or
topic, so kinds and keys can never collide.

### `onServiceCall()` — correlation across processes

```php
$this->inFlightCalls[$request->correlationId] = $handle->id;

async(function () use ($handle, $request): void {
    try {
        $result = $this->client->callService(...);
    } catch (Throwable $e) {
        $result = ServiceResult::failed($request->domain, $request->service, $e->getMessage());
    } finally {
        unset($this->inFlightCalls[$request->correlationId]);
    }

    $handle->send(new ServiceCallResult($request->correlationId, $result));
})->ignore();
```

Two things make the worker-side `callService()` read like a local call:

1. **Its own fiber per call** — concurrent calls do not serialize.
2. **Failure becomes a value, not an exception.** The worker *always* gets a
   `ServiceCallResult` back, so the suspended app fiber always resumes. There
   is no path where an app waits forever because Home Assistant returned an
   error.

### `onWorkerGone()`

```php
if ($handle->isClosed()) { return; }        // idempotent

$handle->terminate();
$this->registry->removeWorker($handle->id); // stop routing to a corpse

if (!$this->running) { return; }            // expected during shutdown

$this->logger->error('Worker died', [...]);
```

The `!$this->running` check keeps logs honest. Workers exiting during `stop()`
is the *correct* outcome; logging `Worker died` at error level for each would
turn a clean shutdown into what looks like a cascading failure. Deregistration
still happens either way — only the alarm is suppressed.

### Observations

- **The cache invalidation in `onStateChanged()` looks unnecessary.**
  `matching()` depends on `$this->exact`, `$this->patterns`, `$kind` and
  `$key` — all registration state plus a string. Entity existence never enters
  the computation, and the registry already flushes where the real dependency
  changes, in `add()` and `remove()`. As written it discards a warm cache on
  every entity appearance and removal, and HA startup is exactly when entities
  appear in bulk. If the motivation was "patterns pick up entities that appear
  later", that property already holds without it, because a new entity is a
  cache miss by definition.
- **No worker is ever restarted.** `onWorkerGone()` deregisters and logs;
  nothing respawns. The README lists "worker pool with process supervision"
  under Working — true as stated, but most readers will take "supervision" to
  mean "restarts". Today a worker crash permanently removes its automations for
  the life of the process.
- **`$inFlightCalls` is a counter wearing a router's clothes.** The reply is
  delivered via the handle captured in the closure; the map is never read to
  route anything. Its only consumer is `stats()`, which has no callers.
- **Two catch blocks, one body** in `listenTo()` — the expected-disconnect case
  and the unexpected one do exactly the same thing.
- **`removeWorker()` flushes the whole cache once per subscription**, since
  each `remove()` clears it.

---

## 7. The channel protocol

### Direction encoded as a type

```php
interface BrokerMessage {}
interface WorkerMessage {}
```

Two empty marker interfaces, and the best idea in this layer.

```php
public function send(BrokerMessage $message): void
private function handleWorkerMessage(WorkerHandle $handle, WorkerMessage $message): void
```

The broker **cannot** send a `Pong`. Not "should not" — the signature rejects
it, statically, at PHPStan max. Compare the usual approach: a `type` string
field and a `switch` that trusts the sender.

`Transport::send(object $message)` is deliberately *not* narrowed. The
transport is direction-agnostic plumbing; direction is a runtime-layer concern,
enforced one level up where the two sides actually differ.

### The twenty

| Broker -> Worker | Worker -> Broker |
|---|---|
| `Bootstrap` | `WorkerReady` |
| `StateSnapshot` | `Subscribe` |
| `StateResynced` | — |
| `StateChanged` | `Unsubscribe` |
| `EventFired` | — |
| `SubscriptionAck` | `ServiceCallRequest` |
| `ServiceCallResult` | `Publish` |
| `TopicMessage` | `AppFailed` |
| `Ping` | `Pong` |
| `Shutdown` | `LogRecord` |

Every one is `final readonly` with promoted constructor properties. No getters,
no `toArray()`, no builders. They go on the wire *as objects*.

### `StateChanged::$deliverTo`

```php
/**
 * @param list<string> $deliverTo lists the subscription IDs that matched, computed once by the broker so each
 *     worker does not re-evaluate every pattern. An empty list means the worker should update its state cache and
 *     tell no app about it.
 */
```

The routing invariant restated at the message level, and it earns its place as
a comment because the code cannot say it. Pattern matching happens **once**,
centrally. And the empty list is a *meaningful* value, not a degenerate one:
update, say nothing.

### ADR 3 — the decision that reversed The
brief recommended JSON. The ADR's response is not a counter-preference:

> That option does not exist with a stock `ProcessContext`.

Followed by three citations from reading `amphp/parallel` v2.4.0's source.
`ProcessContext::start()` hardcodes `new StreamChannel($socket, $socket)` with
no serializer argument; `ProcessContextFactory` exposes no parameter to pass
one; the child's `runContext()` is called without one. `NativeSerializer` wraps
`\serialize()`. The wire format is `serialize()`, unconditionally, unreachable
through any public API.

Then the reframe: typed message classes let PHPStan check both ends, whereas a
JSON string is `mixed` at the boundary. The constraint was discovered, verified
in a spike, and turned into the design rather than worked around.

### The consequence that shaped the public API

> **Closures cannot cross a process boundary** — confirmed in the spike, which
> raised `Amp\Serialization\SerializationException`. This is not a design
> preference.

Follow it forward:

```php
final readonly class Subscribe implements WorkerMessage
{
    public function __construct(
        public string $subscriptionId,
        public string $appId,
        public SubscriptionKind $kind,
        public Selector $selector,      // a value, because a callback cannot be here
    ) {}
}
```

This is why `Selector` exists at all. Why it has `matches()`, `key()` and
`exactMatch()` rather than being a `callable`. Why `Selector::from()` has to
infer intent from a *string*. Why the registry can index on
`exactMatch()`.

And ultimately why an app writes
`$ha->stateChanges('sensor.*')->debounce(...)->subscribe(fn ($e) => ...)` in two
halves: the **selector** crosses the process boundary and routes in the broker;
every **closure and operator** after it never leaves the worker. Nearly
everything in sections 5 and 6 is downstream of this.

### `AppFailed::$trace`

```php
/**
 * @param list<array<non-empty-string, scalar|list<scalar>>> $trace
 *     as produced by Amp\Parallel\Context\flattenThrowableBacktrace()
 */
```

ADR 3's third consequence, implemented. A stack frame's `args` can hold
anything — a resource, a PDO handle, a closure — and serializing *that* throws.
Without flattening, the failure report about an error would itself fail to
serialize.

### `StateSnapshot::$json` — the deliberate exception

Every other message ships typed objects; this one ships a JSON **string**.
`StateCache::encodedSnapshot()` memoizes, and every mutator invalidates:

```php
/** Invalidated on every write; rebuilt lazily on the next snapshot request. */
private ?string $encoded = null;
```

Boot eight workers and you `json_encode` thousands of entities **once**, then
hand the same string to eight channels. And `loadEncodedSnapshot()` ends with
`$this->encoded = $json`, so the worker's state cache starts with its own encoded
form already warm, for nothing.

### `ChannelTransport` — thin, three specific jobs

1. **Exception translation.** `ChannelException` becomes a
   `TransportException`, one of the `StewartException` area classes. Nothing in the runtime ever
   catches an amphp type, which is what keeps ADR 3's escape hatch — swap the
   transport, roughly 150 lines — viable rather than aspirational.
2. **Asymmetric close semantics.** Sending to a closed channel is an *error*;
   reading from one is *EOF*. That is precisely the distinction
   `Broker::listenTo()` converts into its `$reason` string.
3. **A type guard**, because `Channel::receive()` is `mixed`.

### Observations

- **`SubscriptionAck` can only ever say yes.** It carries `bool $accepted` and
  `?string $reason`, but `onSubscribe()` hardcodes `true` with no reason.
  Rejection is unreachable.
- **`Ping` is in the protocol but never on the wire** — a 16-message protocol
  with 15 live messages.
- **One coupling from `Ipc` into `Runtime`.** `Bootstrap` is the only
  `use Stewart\Runtime\...` under `src/Ipc/`, importing
  `Runtime\Config\AppDefinition`; thirteen files go the other way. Given the
  package declares `Stewart\Ipc\` and `Stewart\Runtime\` as two separate PSR-4
  roots, that single arrow is the one thing stopping `Ipc` from being
  extractable.

---

## 8. The worker process

### `bin/worker.php` — fourteen lines

```php
/** @param Channel<object, object> $channel */
return static function (Channel $channel): string {
    return new WorkerRuntime(new ChannelTransport($channel))->run();
};
```

The file **returns a closure**. That is amphp/parallel's child contract: the
entrypoint returns a callable, the runner hands it the `Channel`, and its
return value becomes the summary the parent receives from `$context->join()` in
`Broker::stop()`.

`WorkerRuntime::run()`'s last line:

```php
return sprintf('worker %d stopped (%s, %d calls abandoned)', $this->workerId, $this->stopReason, $abandoned);
```

The child writes its own epitaph and the parent logs it — a dying process
reporting whether it left anyone hanging.

There is no `require vendor/autoload.php`; amphp's process runner bootstraps
that before including the file.

### Two-phase boot, and why

Construction happens on `Bootstrap`. **`initialize()` happens on
`StateSnapshot`.** That split is not incidental — `DemoApp::initialize()` reads
state immediately. If it ran at `Bootstrap` time, every app would open its eyes
in an empty world: `state()` null for everything, `states()` empty, and the
"watched entity does not exist" warning firing spuriously for every app in the
system.

**Construct against dependencies, initialize against data.** And `WorkerReady`
is sent from `onSnapshot`, which makes "ready" mean *state cache seeded, apps
initialized, subscriptions announced* — the only definition useful to the
broker.

### `loadEncodedSnapshot` in place

```php
// In place, not a new instance: every app context already holds this object.
$this->stateCache->loadEncodedSnapshot($message->json);
```

The state cache was built in the constructor and passed by reference into
`WorkerHaContext` and every per-app context. Assigning a new `StateCache` would
leave every app holding an orphaned empty cache — a bug that would present as
"state is always null" with no error anywhere. This is why `StateCache` has
*both* a mutator and a static factory.

### `AppArguments::reject()` — pre-flight, not post-mortem

Returns the reason an app cannot be built, or null. Class exists, implements
`App`, instantiable, and **every option name matches a constructor parameter**.
A variadic constructor accepts anything by name, so it returns early.

The reason this must run *before* the container is built: Symfony's
`setArgument('$brightnes', ...)` for a parameter that does not exist throws at
**compile** time, and one compile covers **every app in the worker**. A single
typo in one app's options would take down every automation sharing that worker.
Filtering first gives the bad app its own `AppFailed` and lets everyone else
run.

> `option "brightnes" matches no constructor parameter of HallLight. It accepts: ha, watch, light.`

### `AppArguments::escape()` — the `%` footgun

```php
if (\is_string($value)) { return str_replace('%', '%%', $value); }
if (\is_array($value)) { return array_map(self::escape(...), $value); }
```

Symfony DI resolves `%foo%` in an argument as a **container parameter**. A user
option like `watch: 'a %s and 50%'` would either explode at compile or silently
resolve to something unintended.

### `WorkerContainer::build()` — synthetic, for the opposite reason

Same mechanism as section 2, inverted motivation. In `BrokerContainer`,
synthetic meant "cannot exist yet". Here it means "already exists" — the
logger, timers, clock, scheduler, context and stores were all built before the
container and arrive bundled in `RuntimeServices`. Only `Timers` and `Clock` are
registered by class name; the rest sit under dot-prefixed ids such as
`.stewart.ha`, which autowiring never matches, and reach user wiring only
through contract aliases (`HaContext`, `LoggerInterface`, `Scheduler`, `Store`,
`ReadableStore`). The transport, state cache and dispatcher are not in the container
at all.

```php
// Public so unused definitions survive compilation and can still be set.
```

Synthetic alone is not enough. If no app type-hints `Scheduler`, the optimizer
deletes the definition and the post-compile `set()` throws.

**Load order is defensive:**

```php
$file->applyTo($container);
```

The user's file is applied first, so later `register()` calls overwrite
anything the project happens to name the same way. `ServicesFile::at()`
includes the file once per process and replays the closure it returned into
each container, so a file that declares a function survives the per-app
fallback compiles. Having no `services.php` is
"the normal case, not a degraded one" — an early return, no error.

### Per-app identity, by construction

```php
$container->register($loggerId, WorkerLogger::class)
    ->setFactory([new Reference(WorkerContainer::LOGGER), 'forApp'])
    ->addArgument($definition->id);

$container->register($contextId, WorkerHaContext::class)
    ->setFactory([new Reference(WorkerContainer::CONTEXT), 'forApp'])
    ->setArguments([$definition->id, new Reference($loggerId)]);
```

Every app gets its **own** `HaContext` and logger, cloned from the worker-level
pair with its ID baked in. That is how an injected `LoggerInterface` arrives already tagged, and
how `publish()` knows `publisherAppId` without the app supplying it. An app
never handles its own ID, so it cannot log as, or publish as, another app.

```php
->setAutowired(true)
->setBindings([
    HaContext::class => new BoundArgument(new Reference($contextId), false),
    LoggerInterface::class => new BoundArgument(new Reference($loggerId), false),
]);
```

Container-wide aliases point `HaContext` and `LoggerInterface` at the
worker-level instances; the per-service **bindings override them** for this one
app. Normal autowiring still resolves the user's own services from
`services.php`, while those two types resolve per-app. The `false` is
`$trackUsage`, suppressing Symfony's unused-binding report.

`StoreAttributesPass` runs before optimization and turns `#[GlobalStore]` and
`#[PeerStore]` parameters into explicit arguments. Bindings only fill
arguments that are still empty, so the attribute beats the app's own `Store`
binding, and an argument written in `services.php` beats the attribute.

### The container is thrown away — and that is the point

```php
// $services goes out of scope here, and nothing keeps a reference to it.
// That is the invariant that lets a container live in a process running
// an event loop: it wires the apps, then it is gone.
```

A DI container in a long-running process is normally a slow leak. Here its
entire job is construction; `$this->apps` holds the `App` instances and
everything else is garbage the moment `onBootstrap` returns.

The cost of that invariant: **there is no runtime service resolution**. An app
cannot ask the container for something later. Which is precisely why `reject()`
has to be pre-flight — there is no second chance to discover a wiring problem.

### Failure isolation, phase by phase

| phase | where | what happens |
|---|---|---|
| `Construct` | `reject()`, container build, `typed()` | app dropped, `AppFailed` sent |
| `Initialize` | `AppLifecycle::initialize()` | app dropped **and its subscriptions and schedules released** |
| `Handler` | `onHandlerError` -> dispatcher, or a scheduled handler | reported with its `origin`, app keeps running |
| `Schedule` | a `Schedule` failing to compute its next occurrence | reported, that task cancelled, app keeps running |
| `Dispose` | `AppLifecycle::dispose()` | reported, shutdown continues |

The `Initialize` case is the careful one:

```php
$this->resources->release($id);
$this->markFailed($id, AppFailurePhase::Initialize, $e);
```

An app that throws *halfway* through `initialize()` may have already registered
three subscriptions and a schedule. Without `AppResources::release()`, a
half-constructed app would keep receiving events and firing timers forever —
and release is final, so a fiber it left running cannot register more. Dropping it from the lifecycle's `running` map also means it
will not be disposed later, which is correct — it never finished initializing.

### `AppFailureReporter::report()` — ADR 3, twice

```php
// amphp declares this shape as a file-local @psalm-type PHPStan cannot expand.
/** @var list<array<non-empty-string, scalar|list<scalar>>> $trace */
$trace = Context\flattenThrowableBacktrace($error);
```
```php
// Not the Throwable itself: stack frames may not survive serialization.
```

And when reporting the failure itself fails, it writes to stderr — the raw pipe
from section 5 earning its keep, because when the channel is broken that is the
only way a message reaches you.

### Observations

- **A second `StateSnapshot` would double-initialize every app.**
  `onSnapshot()` unconditionally starts `AppLifecycle::initialize()`, registering a second
  set of subscriptions with no deduplication. Latent today, since the broker
  sends exactly one per worker — but `snapshotRevision` exists precisely to
  support resync, and resync means a second snapshot. A guard costs one
  boolean.
- **The construct-then-initialize contract is undocumented where it matters.**
  `App::initialize()`'s docblock says "Register subscriptions and schedules".
  It does not say state is guaranteed populated here and was not during your
  constructor — the actual rule, and the one the whole two-phase boot exists to
  provide.
- **A container compile failure blames every app identically** — N identical
  `AppFailed` messages for one root cause. Defensible, but the log reads as N
  failures.
- **`onSubscriptionAck` is a live receiver with no live sender.** It handles
  `!$message->accepted` properly; the broker hardcodes `true`.
- **Seven fields are initialized twice, and one must not be.** The constructor
  builds a throwaway `PendingCalls`, `WorkerLogger`, `DateTimeZone` and
  `LocalDispatcher`, all replaced in `onBootstrap`. But `$this->stateCache` is
  created once and must **never** be replaced — and the only thing saying so is
  a comment 135 lines later.

---

## 9. `HaContext` and the dispatch layer

### The interface lives in `contracts`

No transport, no channel, no process model. That is what lets a test double —
and later the generated entity classes — exist without dragging in a transport
or a process model.

The performance contract is in the docblocks and restated at the
implementation:

```php
/** A hash lookup in this process. */
public function state(EntityId|string $entityId): ?EntityState
{
    return $this->stateCache->get(EntityId::from($entityId));
}
```

The payoff for ADR 4's full state cache: reads never leave the process, never
suspend, never fail.

### `EventStream` + `Subscription` — a two-step on purpose

`stateChanges()` returns a `StateChangeStream`, `topic()` returns
`EventStream<TopicEvent>`, so PHPStan types the closure's parameter at the call
site without annotation.

**Nothing happens until `subscribe()`.** Calling `stateChanges('sensor.*')` and
discarding it allocates one object and sends no message. Each operator is a
decorator wrapping the stream below it, so a whole chain costs one object per
operator; the `Subscribe` message fires once, at the end, carrying only the
source selector.

`subscribe()` is `final` on `OperatorStream` because it does three things in a
fixed order: it mints one `SubscriptionScope` for the subscription, walks the
chain downwards through `attach()` so every operator can build its state and
register its teardown, and then hands the resulting `Subscription` back to the
scope so `take()` and `takeUntil()` can cancel themselves.

That scope is also why a `debounce` timer cannot break the dispatch invariants:
its emission goes into the subscription's own `SubscriptionQueue` rather than
being called on the timer's fiber.

### `forApp()` — sharing by reference, differing by identity

Every collaborator is passed through by reference — especially the state cache,
which is the *one* `StateCache` in the process. That aliasing is exactly what
the in-place `loadEncodedSnapshot()` was protecting. Only `$appId` and
`$logger` differ.

### `callService()` — the fiber suspension, end to end

```php
$correlationId = $this->pending->nextId();
$future = $this->pending->expect($correlationId);      // register FIRST

try {
    $this->transport->send(new ServiceCallRequest(...));
} catch (Throwable $e) {
    $this->pending->forget($correlationId);
    return ServiceResult::failed($domain, $service, 'Could not reach the broker: ' . $e->getMessage());
}

try {
    return $future->await(new TimeoutCancellation($this->callTimeout));
} catch (Throwable $e) {
    $this->pending->forget($correlationId);
    return ServiceResult::failed($domain, $service, $e->getMessage());
}
```

`expect()` before `send()` — the deferred exists before the request can
possibly be answered.

```
app fiber                  worker loop              broker                 HA
callService()
  expect(id) ----------> PendingCalls
  send -------------------------------> ServiceCallRequest
  await()   SUSPENDS                       async fiber ---------> callService
                                                       <--------- result
                                           ServiceCallResult
                       <----------------------------
              handle(): pending->resolve(id, result)
  RESUMES  <--------- deferred->complete()
returns ServiceResult
```

The app's fiber parks at `await()`. The worker's receive loop keeps turning the
whole time — that is the entire point of fibers here. When the reply lands,
`resolve()` completes the deferred and the app resumes mid-statement.

**`callService()` cannot throw.** Three failure paths — unreachable broker,
timeout, any other await failure — all return `ServiceResult::failed(...)`.
Combined with the broker converting an HA exception the same way, there is no
path where a service call kills a handler or hangs forever.

### Correlation IDs without coordination

```php
/** Unique across the daemon without coordination: worker ID plus a local counter. */
public function nextId(): string
{
    return $this->workerId . ':' . $this->counter++;
}
```

No UUID, no shared sequence, no locking. `LocalDispatcher::register()` does the
same with `'w' . $workerId`. A composite key whose first component is already
unique by construction.

A reply arriving after a timeout is normal, not an error — `resolve()` on an
unknown ID returns quietly.

### `LocalDispatcher` — one class, two deployment models

```php
$targets = $deliverTo === null
    ? $this->matchLocally($kind, $key)      // run every selector here
    : $this->resolve($deliverTo, $kind);    // trust the broker's answer
```

- **Worker mode**: the broker already matched, so this is a hash lookup.
  Pattern matching never happens twice.
- **In-process mode**: `null`, and the dispatcher does its own matching.

One nullable parameter is the entire difference between the multi-process
runtime and a single-process one.

```php
// Cancelled between the broker routing the event and it arriving. Not an error.
```

A real cross-process race, skipped silently and correctly.

### `invoke()` — every handler gets a fiber

```php
async(function () use ($subscription, $event): void {
    try {
        ($subscription->handler)($event);
    } catch (Throwable $e) {
        $this->logger->error('Handler threw', [...]);
        if ($this->onHandlerError !== null) {
            ($this->onHandlerError)($subscription->appId, $e);
        }
    }
})->ignore();
```

This is what makes `callService()` *inside* a handler work at all. Inline
handlers would mean a suspending handler blocks the dispatch loop, and behind
it the receive loop, and behind that every app in the worker.

Two consequences worth naming:

- **Handlers are not ordered relative to each other** once any of them
  suspends. Two subscriptions on the same entity may interleave.
- **A throwing handler is not fatal.** Logged, reported as `AppFailurePhase::Handler`,
  app keeps running. Compare `initialize()` failing, which removes the app
  entirely. The asymmetry is right: a bad event should not be terminal, a bad
  setup should.

### Who actually unsubscribes

`AppResources` releases an app's subscriptions and schedules together: on a
construct or initialize failure, after each app's `dispose()`, and once more
for everything after the disposal grace window. The broker also cleans its side
up in `onWorkerGone()`, so a worker that dies without releasing leaves nothing
routed to it.

### Observations

- **`callTimeout` is unconfigurable, and it races the broker's timeout.**
  `WorkerHaContext::$callTimeout = 30.0` is a constructor default and no call
  site ever passes it. Meanwhile `home_assistant.command_timeout`, also
  defaulting to `30.0`, feeds `ConnectionConfig` and is used by the broker's
  `HaConnection`. Two timeouts **in series** with identical values: the worker
  starts its clock, sends the request, the broker starts its own clock on
  receipt. A call taking 29.9 s in Home Assistant can return a real result to a
  worker that already gave up, and the app sees `failed` with a timeout message
  for a call that actually succeeded. The outer timeout needs to be strictly
  larger, and raising `command_timeout` in config today does nothing for the
  worker.
- **`LocalDispatcher::all()` is unused** — zero call sites.
- **`WorkerHaContext` is `final class`, not `final readonly class`**, despite
  every property being readonly and `forApp()` using `new self()`. Every
  comparable class in the codebase is `final readonly`.
- **Handler ordering and concurrency are not documented anywhere
  user-facing** — nor is the survivable-handler / fatal-initialize asymmetry.
  Both are things an automation author will eventually depend on.

---

## 10. The client package

`packages/client` is usable standalone. It knows nothing about brokers,
workers, or the channel protocol.

### Two layers, one seam

| | speaks | job |
|---|---|---|
| `HaConnection` | arrays | socket, auth, read loop, JSON, correlation |
| `HaClient` | value objects | typed commands |

The seam is exactly one method: `send(array $command): array`. Below it,
everything is `mixed` and arrays and defensive `is_array()` checks. Above it,
`getStates(): array<string, EntityState>`, `callService(): ServiceResult`,
`getTimeZone(): DateTimeZone`. The untyped part of the world is contained in
one class.

### `ConnectionConfig::normalizeUrl()`

Invoked from the config schema's `beforeNormalization`. Accepts
`homeassistant.local:8123`, `http://`, `https://`, `ws://`, `wss://`, with or
without the path. Idempotent — the `str_ends_with` guard means running it twice
changes nothing, which matters because it runs from the schema *and* the VO can
be constructed directly.

### `__toString()` — a token-safe rendering

Scheme, host, port. No path, no query, no userinfo. And it is what every log
line and error message uses. Combined with `ConfigDumpCommand`'s secret
masking, that is two independent mechanisms keeping the long-lived token out of
output — one at the config layer, one at the connection layer.

### `connect()` — the factory nest, and why

```php
new Rfc6455Connector(
    connectionFactory: new Rfc6455ConnectionFactory(
        parserFactory: new Rfc6455ParserFactory(
            messageSizeLimit: $this->config->messageSizeLimit,
            frameSizeLimit: $this->config->frameSizeLimit,
        ),
    ),
);
```

Three levels of factory to set two integers — the only route amphp offers. The
schema comments explain why they are configurable at all:

```
'A full get_states for a large instance is several megabytes in one message.'
'The frame limit bites before the message limit, and looks like a disconnect.'
```

That second line is hard-won. Exceeding the frame limit does not raise "message
too large" — the socket just closes.

**The catch ordering:**

```php
} catch (CancelledException $e) {                      // timeout: specific, actionable
} catch (AuthenticationFailed | ConnectionLost $e) {
    throw $e;                                          // ours: do not double-wrap
} catch (Throwable $e) {                               // everything else: wrap with context
```

And the timeout message is a model of the genre:

> Timed out after 10s connecting to Home Assistant at ws://homeassistant.local:8123.
> Check home_assistant.url, that Home Assistant is running, and that this container can reach it.

Three hypotheses in descending likelihood, ending with the container-networking
one that is *the* classic Docker failure for this kind of tool.

### `authenticate()` — two protocols on one socket

```php
/** The pre-auth exchange: `auth_required` -> `auth` -> `auth_ok`. */
```

This uses `readOne()` — a direct, blocking receive — not the read loop, which
does not start until authentication returns. Home Assistant's auth messages
carry **no `id` field**, so they cannot be correlated. The handshake is a
synchronous request/response protocol; everything after it is multiplexed by
`id`. Two protocols on one socket, and the code's structure mirrors the split
exactly.

The rejection message names the variable: *"Check
STEWART_HOME_ASSISTANT__TOKEN in .env."*

### `readLoop()` — the same shape as `Broker::listenTo()`

Every pattern here has a twin elsewhere:

- **Inner try inside the loop** — one malformed message does not kill the
  reader. The outer catch does, and should.
- **`failAll` plus an abandoned count** — identical to `PendingCalls::failAll()`
  in the worker, reported the same way.
- **`if (!$this->closing)`** — the same idea as `Broker::onWorkerGone`'s
  `if (!$this->running)`. A disconnection you asked for is not a warning.

`MessageCorrelator` is `PendingCalls`' sibling: `nextId`/`expect`/`resolve`/
`fail`/`failAll`, with `int` ids because HA's protocol numbers them.

### `dispatch()` — three types, one normalization

```php
match ($type) {
    'result' => $this->correlator->resolve($id, $message),
    'event'  => $this->dispatchEvent($id, $message),
    'pong'   => $this->correlator->resolve($id, ['success' => true, 'result' => []]),
    default  => null,
};
```

The `pong` line is quietly doing work: HA's pong carries no `success` or
`result` field, but `send()`'s contract expects that envelope. Rather than
special-casing the reader, it synthesizes the shape at the boundary.

### One ID space, two lifetimes

`subscribeEvents()` takes an id from the same sequence as commands, uses it to
await the ack, *and* keeps it in `$eventHandlers[$id]` indefinitely — because
in HA the subscription id **is** the command id, and every later `event`
message carries it.

So the correlator's pending map and the event-handler map are keyed from one
space with different lifetimes: pending clears on the ack, handlers persist. It
reads like a leak until you see why, and both failure paths unset the handler
so a rejected subscription leaves nothing behind.

### `HaClient::callService()` — failure as a value, again

It catches only `HaCommandFailed` — HA received the command and said no. A
`ConnectionLost` propagates up into `Broker::onServiceCall`'s
`catch (Throwable)`, which converts it to `ServiceResult::failed` anyway. Two
layers, same policy, so the worker's suspended fiber always gets an answer.

Optional fields are built conditionally, because HA rejects some commands that
carry empty optional keys.

### Observations

- **No reconnect — and the daemon does not exit either.** When `readLoop()`
  ends it nulls the socket, fails everything in flight, logs one warning and
  returns. Nothing reconnects; nothing stops. `Broker::run()` is still parked
  on its future, which only completes in `stop()`. So a Home Assistant restart
  leaves Stewart running with a dead socket, workers alive, apps subscribed,
  and `callService()` failing forever — a zombie that looks healthy to
  `docker ps` and to any supervisor watching the exit code. Until reconnect
  exists, the right failure mode is to exit non-zero and let the supervisor
  restart.
- **A bare host normalizes to plaintext `ws://`**, carrying a long-lived token
  in the clear. Fine on a LAN, which is the expected case, but the same rule
  silently applies to a public hostname.
- **`send()` falls through after `fail()`.** On a send error it errors the
  deferred and continues to `await()`, which throws immediately with the right
  exception. Correct, but it reads as a missing `return`, and an edit inserting
  anything between those statements breaks it.
- **`catch (AuthenticationFailed | ConnectionLost)` in `connect()` looks
  unreachable** — the obvious source of both is `authenticate()`, which is
  called outside the try block.
- **Docblock typo** in `HaClient::callService()`: two sentences run together.
- **Unused public API, defensibly.** `MessageCorrelator::pendingCount()`,
  `HaClient::getServices()`, `HaClient::isConnected()`,
  `HaClient::subscribeStateChanges()` and `HaClient::subscribeEvents()` have no
  internal callers. Unlike the runtime cases, this package is *declared*
  standalone-usable, so a complete surface is the point; the two subscribe
  methods are tested, and `subscribeEvents('state_changed')` throws so state
  changes never take the generic path. `pendingCount()` is the odd one out.

---

## 11. The rest

### `InProcessHaContext` — the second implementation

Same interface, no broker. `callService()` goes straight to the client;
`publish()` goes straight to `dispatchTopic()` with **no `$deliverTo`** — which
is the whole reason `LocalDispatcher` has its null branch.

Two contrasts with `WorkerHaContext`:

- **No `$callTimeout`.** It calls the client directly, so the only timeout is
  `commandTimeout`. The double-timeout only exists on the path that crosses
  processes.
- **Nothing feeds its cache.** It holds a `StateCache` *and* a `HaClient` but
  never wires `subscribeStateChanges` -> `cache->apply` ->
  `dispatcher->dispatchStateChange`. Whoever assembles it must do that, and no
  assembler exists — it is constructed nowhere in `packages/`, `apps/` or
  `bin/`.

A correct half of a single-process mode, waiting for the other half.

### `WorkerLogger` — PSR-3 over a channel

`AbstractLogger` gives all eight level methods funnelling into one `log()`,
which ships a `LogRecord` and falls back to stderr:

```php
} catch (Throwable) {
    // The broker is gone; stderr keeps the message through a shutdown race.
```

`serializable()` is ADR 3 once more. Log context is user-supplied `mixed` — a
closure or a PDO handle would throw `SerializationException`, and the catch
above would silently demote a good log line to stderr. So it pre-flattens:

```php
$out[$key] = match (true) {
    $value === null, \is_scalar($value) => $value,
    $value instanceof Throwable         => $value::class . ': ' . $value->getMessage(),
    \is_array($value)                   => array_map(/* one level, then get_debug_type */),
    $value instanceof Stringable        => (string) $value,
    default                             => get_debug_type($value),
};
```

Note the **one-level** array flattening: a nested array becomes the string
`"array"`. A deliberate depth cap, but silent. `EchoApp` works around it by
`json_encode`ing its payload before logging.

### `Logging`

Monolog, line or JSON formatter, always to stdout, one handler. Combined with
`Broker::onLogRecord()` folding every worker's records in, that is the single
ordered log stream. `dateFormat: 'H:i:s'` — time only, no date.

### The value objects

`EntityState::fromArray()` is defensive at **every** field and cannot throw.
Correct for a boundary VO: Home Assistant can send anything, and the worst
outcome should be a degenerate state object, never a crash in the read loop.

**Timestamps are `?Instant`, parsed once at decode.** An `Instant` is a single
integer of epoch microseconds, so it costs little to keep twice per entity and
to serialize into every snapshot. The client's `EventDecoder` reads Home
Assistant's text, turns garbage into `null` and warns once per entity and field.

`StateChange`'s predicates are where the semantics live:

```php
public function changedTo(string $state): bool
{
    return $this->to?->state === $state && $this->from?->state !== $state;
}
```

An **edge**, not a level. An attribute-only update where the state stayed `on`
does *not* satisfy `changedTo('on')`.

`ServiceResult` has a **private constructor and named factories** — you cannot
construct a success that carries an error message. And `throwOnFailure(): self`
returns `$this`, so it chains. The default is total; strict mode is one call
away.

`StewartException extends RuntimeException` is the **one abstract root** of every
error, and each package's `Exception` namespace holds final area classes built only
through coded factories. So
`catch (StewartException)` catches everything Stewart throws, which is what
`RunCommand` and `ConfigDumpCommand` rely on to turn any failure into one clean
line.

`HaEvent::stateChange()` is the single translation point from HA's wire shape.
Malformed payloads return null and get dropped, not thrown on. Its three
consecutive `@phpstan-ignore-next-line argument.type` are honest suppressions —
`is_array()` narrows to `array<array-key, mixed>` and `fromArray()` wants
`array<string, mixed>` — but `EntityState` already has a private `stringKeyed()`
that would remove all three.

### `stewart doctor`

Every check explains itself:

```php
'pcntl' => 'signal handling; without it graceful shutdown is impossible',
'json'  => 'HA wire format',
'ctype' => 'config and cpu-count parsing',
```

The version check names the real constraint rather than asserting one:
`'need >= 8.4.1 (phpunit ^13 and symfony/console ^8 both require it)'`.

The best check **actually binds a Unix socket** and unlinks it, rather than
testing `extension_loaded('sockets')` and calling it done. The extension can be
loaded while the sandbox forbids binding, and that failure would otherwise
surface as "workers will not spawn" with no cause. Exits non-zero, so it is
CI-usable.

### `bin/bench-container.php`

Times constructing apps directly against `WorkerContainer::build()` plus
resolution, at 2/10/30 apps, printing the ratio and heap delta — with an inline
anonymous null `Transport` so nothing reaches for a broker. The artifact of
someone checking whether a DI container is affordable per worker process
instead of assuming either way.

### `EchoApp` — a cross-worker sample

`demo` is pinned to worker 0, `echo` to worker 1. A log line from `EchoApp`
shows `publish()` -> `Publish` -> broker -> `SubscriptionRegistry::route` ->
`TopicMessage` -> `LocalDispatcher` working across two processes. It is a
sample, not a fixture: the protocol suite uses its own apps under
`tests/Fixtures/Protocol`.

---

## Consolidated observations

A review snapshot taken 2026-09-16 against the first pass. Section numbers in
brackets.

Items 1, 3, 4, 5, 8 and 9 have been addressed. The findings below are
left as they were written, because a dated snapshot that gets edited stops
being evidence of anything.

### Operational

1. **No reconnect, and no exit either** [10]. A Home Assistant restart leaves a
   zombie: dead socket, live workers, `callService()` failing forever, zero
   exit code. Until reconnect exists, failing the process is strictly better
   than the current silence.
2. **`callTimeout` races `commandTimeout`** [9]. Both 30.0, in series, the
   outer one unconfigurable and never wired. A slow-but-successful call can
   report failure.
3. **No worker restart, despite the README claiming "process supervision"**
   [6]. A crash permanently removes those automations.
4. **A failed worker start does not name its orphaned apps** [5]. `$apps` is in
   scope at the log call.

### Latent hazards

5. **A second `StateSnapshot` double-initializes every app** [8] — duplicate
   subscriptions. `snapshotRevision` exists specifically to enable the resync
   that would trigger this.
6. **A bare host normalizes to plaintext `ws://`** [10], carrying a long-lived
   token.
7. **`onStateChanged`'s cache invalidation looks unnecessary** [6] —
   `matching()` depends on registrations and the key string, never on entity
   population. Flushes a warm cache during exactly the bulk-appearance events
   it appears meant to handle.

### Built but unreachable

Worth naming as a group, since each invites a reader to assume a behavior that
does not exist.

8. `Ping`/`Pong` — never sent; the "is an app blocking?" warning cannot fire.
9. `SubscriptionAck::$accepted` — hardcoded `true`; the worker's rejection
   handler is live, the sender is not.
10. `AppDefinition::$enabled` — can only ever be `true`.
11. `Broker::stats()`, `Broker::ping()`, `Services::container()`,
    `ConfigLoader::fromGlobals()`, `LocalDispatcher::all()`,
    `MessageCorrelator::pendingCount()` — no call sites.
12. `NotConnected`, `ServiceResult::throwOnFailure()`, `ServiceTarget` (threaded
    through every signature, constructed nowhere), `InProcessHaContext` (no
    assembler) — defined, never used in production code. `contracts` and
    `client` are declared standalone-usable, so a complete surface is
    defensible there; `NotConnected` and the runtime cases are not covered by
    that.

### Smaller

13. `assignApps()` wraps out-of-range pins silently [3].
14. `comparable()` duplicated in `AppCatalog` and `EnvironmentOverlay` [4].
15. A namespace/path mismatch under `apps/` fails silently *after* executing
    the file [4].
16. `Ipc` -> `Runtime\Config\AppDefinition` is the single arrow preventing
    `Ipc` from being extractable [7].
17. `HaConnection::send()` falls through after `fail()` — correct, but reads as
    a missing `return` [10].
18. `catch (AuthenticationFailed | ConnectionLost)` in `connect()` is
    unreachable from its obvious source [10].
19. `WorkerHaContext` is `final class` where every sibling is `final readonly`
    [9].
20. Docblock typo in `HaClient::callService()`; three stacked PHPStan
    suppressions in `HaEvent` that a private `stringKeyed()` would remove [11].

### Documentation gaps

21. `App::initialize()`'s docblock does not state the contract the two-phase
    boot exists to provide: state is populated here and was not in your
    constructor [8].
22. Handler concurrency is undocumented — two handlers on one entity can
    interleave once either suspends; a throwing handler survives while a
    throwing `initialize()` kills the app [9].
23. `Logging` uses `H:i:s` with no date, which gets painful in collected logs
    [11].
