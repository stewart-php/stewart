---
title: 'The worker'
description: 'Worker boot, building apps, HaContext, streams and dispatch, a service call end to end, schedules, pause and logging.'
---

## Entry

`bin/worker.php` returns a closure, which is amphp/parallel's contract for a child process. The closure wraps the
`Channel` in a `ChannelTransport` with `IpcCodec::createForWorkerBootstrap()` and runs `new WorkerKernel()->run()`. The
string it returns is the epitaph the broker logs. A worker returns; it never calls `exit()`, because amphp writes the
result channel only after the callable returns.

`Kernel/WorkerKernel` receives `Bootstrap` before any container exists; anything else ends the worker. It creates a
`SystemClock` in the bootstrap time zone, compiles the `Worker` profile with the transport, `Bootstrap`,
`WorkerSettings`, worker ID, clock and Stewart's identity as synthetics, and runs `Worker/WorkerSession`.

## Session

`WorkerSession::run()` installs the loop error reporter and starts two fibers: starting the apps, and
`BrokerMessageReader::readUntilChannelCloses()`. Then it waits for shutdown. On the way out, `PendingRequests::failAll()`
fails every request still waiting.

The reader hands each message to `Worker/Message/BrokerMessageDispatcher`, which finds its one
`BrokerMessageHandler`. An undecodable frame is logged and skipped; a closed channel stops the worker; a handler that
throws is logged and reading goes on.

## Two-phase boot

`WorkerSession::constructAndInitializeApps()`:

1. applies the paused app IDs from `Bootstrap`;
2. constructs every app (`AppLifecycle::constructApps()`). The reader is already running, so a constructor may await
   a service call;
3. waits for the first `StateSnapshot` (`StateCacheSync::awaitFirstSeed()`). `StateSnapshotHandler` seeds the cache
   with `StateCache::replaceAll()`, **in place**: every app's context already holds that `StateCache`, so replacing
   the object would leave them reading an empty world. A later snapshot only reseeds;
4. initializes every app;
5. sends `WorkerReady` with the running and failed app IDs and its memory.

Apps are constructed early so their errors surface at once, and initialized only after the cache is seeded so
reading state in `initialize()` works.

## Building apps

`Container/AppContainerBuilder::buildAppContainer()` builds one container for all of the worker's apps:

- `AppOptionResolver` checks each app's options before compiling: the class exists, every option matches a
  parameter (with a suggestion when it does not), none is repeated, required ones are set, types coerce. It escapes
  `%` to `%%`, which Symfony would otherwise read as a parameter.
- The user's `services.php` loads first; an app class defined there is refused.
- Framework services sit under ids starting with a dot (`.stewart.ha`, `.stewart.logger`), which autowiring never
  matches. User code reaches them only through contract aliases, so the transport, the cache and the dispatcher are
  out of its reach.
- Each app gets its own logger, context, scheduler, timers, store, read-only store, MQTT client, exposure and
  registry editor, through `forApp($appId)` factories, and the generated `Entities` and `Services` bound to its
  context.
- `StoreAttributesPass` maps `#[GlobalStore]` and `#[PeerStore]` parameters to store services; a writable peer store
  is an error.
- If compiling fails, `isolateUnbuildableApps()` compiles each app alone, fails only the broken ones, and compiles
  the rest again.

The container is a local variable of `constructApps()`: consulted at boot, then gone. Nothing resolves services per
event.

## App lifecycle and failures

`Worker/AppLifecycle` runs every `initialize()` in its own fiber, in parallel, with `supervision.initialize_timeout`
(`AppError::InitializeTimedOut`; a late finisher is disposed). A success moves the app's `AppSlot` to Running and
activates its scope, which starts its queues and schedules. `failApp()` moves it to Failed, releases its scope, and
reports through `AppFailureReporter`: an `AppFailed` with the phase (Construct, Initialize, Handler, Schedule,
Dispose), the trace as text and the `ExceptionDetails`, sampled when it repeats, and written to stderr if the channel
is gone.

## HaContext

`Worker/WorkerHaContext` is `final readonly`; `forApp()` copies it with another `ResourceScope`, so every app shares
the cache and the dispatcher but owns its subscriptions. Reads (`getState()`, `requireState()`, `listStates()`) are
hash lookups on the shared `StateCache` and never suspend. Everything that talks to the broker is in
`Worker/Context/`: `ServiceCaller`, `HistoryReader`, `EventFirer`, `TopicPublisher`, `DispatchStreams`.

## Streams and dispatch

`DispatchStreams::watchStateChanges()` returns a `StateChanges` stream (from `stewart-php/support`) over a
`DispatchSource`; events, topics, triggers and connection events get an `OperatorStream`. The operators live in
`support/src/Stream/ComposedStream.php`. `subscribe()` creates a `SubscriptionScope` and attaches the operator chain;
an operator that emits later, such as a debounce timer, emits through the scope, which delivers into the
subscription's own queue rather than running the handler on the timer's fiber.

`Dispatch/LocalDispatcher::register()` gives the subscription an ID (`<workerPrefix>:<n>`) and a `SubscriptionQueue`.
State-change selectors are indexed locally (`SelectorIndex`, `EntityFilterIndex`); only broker-routed kinds (events,
topics, MQTT, triggers) send a `Subscribe` or `SubscribeTrigger` through `BrokerSubscriptions`. A `StateChangeBatch`
updates the cache first, then is matched locally; events arrive with the broker's matched subscription IDs. A refused
`SubscriptionAck` cancels the subscription.

`Dispatch/SubscriptionQueue` is bounded by `subscription_buffer`, dropping superseded state changes first, then the
oldest. One drain fiber per queue runs the handlers, so one subscription handles its events one at a time while
different subscriptions run concurrently. Nothing drains until the app's scope is live, which is the "nothing before
`initialize()` returns" gate, and a paused scope holds its items. A handler that throws is reported, and the queue
goes on.

## A service call, end to end

1. `WorkerHaContext::callService()` → `Worker/Context/ServiceCaller::sendRequestAndAwait()`. While disconnected, it
   fails at once.
2. `PendingRequests::open()` registers the request before sending, with an ID from `CorrelationIdSequence`
   (`<workerId>:<n>`, unique without coordination).
3. It sends `ServiceCallRequest` and suspends the fiber, with `WorkerSettings::callTimeout` (the Home Assistant
   command timeout plus five seconds) as the backstop. This suspension is what makes a call read like a local one.
4. The broker runs the call (see [The broker](broker.md#service-calls)) and always answers.
5. `CorrelatedReplyHandler` resolves the pending request with the typed reply, or rebuilds the typed exception from
   `RequestFailed`. The caller gets an `EventContext`, or a `ServiceCallException`.
6. `finally` forgets the request, so a late reply is dropped.

## Schedules

`Schedule/WorkerScheduler` (per app through `forApp()`) arms entries in `ScheduleRegistry`, which starts an entry only
while its scope is live. `ScheduleEntry` skips a run while the previous one is still going, suppresses runs while
paused, and holds a paused one-shot until resume. Each run gets its own fiber. Wall-clock schedules recompute their
next occurrence from the clock each time, in hops of at most a minute, so a suspended host or an NTP step does not
make them drift.

## Pause

`Worker/PausedAppsSync` applies the paused set from `Bootstrap` and from `PausedAppsChanged`. `AppResources` pauses a
scope (discarding queued work), resumes it (refreshing entity-time schedules and running held one-shots), and
releases it when the app stops. Scope states are in `Scope/ScopeLifecycle`.

## Logging

`Worker/WorkerLogger` filters by `WorkerSettings::logLevel` and sends each record to the broker as a `LogRecord`, with
the app's scope and the `ExceptionDetails` of a Stewart exception, falling back to stderr. Context values are made
serialisable recursively; a `Throwable` is rendered with its trace, never sent as itself. The broker writes the one
ordered log stream.
