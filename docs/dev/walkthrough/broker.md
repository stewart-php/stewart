---
title: 'The broker'
description: 'Broker boot, the Home Assistant session, spawning workers, routing, the outbox, service calls, supervision and shutdown.'
---

## Boot

`Broker/BrokerLifecycle::run()` is the broker's main line:

1. Install the loop error logger, move `BrokerRun` to Running, install signal handlers.
2. Probe the store. An unreachable store at start is a configuration error, not an outage, so it comes before Home
   Assistant.
3. Restore stored pause overrides.
4. Start the control socket, the HTTP listeners and MQTT, in the background. They answer while Home Assistant is
   still connecting.
5. Open the Home Assistant session (`WebsocketHaSession::open()`).
6. Set the process time zone to Home Assistant's, check the generated classes for drift, start the workers
   (`WorkerStartup`), start the release poller in Git mode.
7. Wait until the run stops.

Between steps it checks `isRunning()`, because a stop can land while a step is suspended. `finally` stops the
poller, the listeners, the control plane and the signal watchers.

## Connecting to Home Assistant

`Broker/WebsocketHaSession::open()` runs `connectSubscribeAndSeed()` inside `Reconnector::retryUntilConnected()`,
which retries with backoff and rethrows only fatal client errors (a rejected token, a non-admin user). Each attempt:

1. marks trigger and component links lost, closes any old socket, connects;
2. sets `establishing` and subscribes to all events through `HaClient::subscribeStateUpdatesAndEvents()`, which
   decodes only `new_state` of each `state_changed`, then re-issues every live trigger and opens the component session;
3. reads the site settings and Stewart's user ID (cached after the first time);
4. calls `get_states` and reads the registries;
5. replaces the registry, then the state cache, replays the state updates buffered meanwhile, and bumps the
   revision.

**Subscribe before seed.** While `establishing`, `onStateUpdated()` buffers updates per entity, so nothing between
the subscription and the seed is lost. Events and trigger fires arriving then are dropped instead, so no handler
reacts with pre-outage state.

## Spawning workers

`Broker/WorkerStartup::startWorkers()` starts every slot concurrently and fails only if none comes up
(`BrokerError::NoWorkersStarted`). It then starts the watchdog, schedules exposure reconciliation, and logs
`Stewart is running`.

`Broker/WorkerPool::startWorker()` moves the slot's `WorkerSlotState` to Spawning (phases: Stopped, Spawning, Live,
Stopping, RestartScheduled, Quarantined; each phase owns its timers, and leaving it cancels them).
`ProcessWorkerSpawner` starts `bin/worker.php <workerId>` through amphp's `ProcessContextFactory` and wraps it in a
`ProcessContextWorker` with a `ChannelTransport` and the `IpcCodec`. A failed spawn is logged with its apps and a
restart is scheduled.

`attachHandle()` then, in this order: builds the `WorkerHandle`; records it on the slot (the strong reference that
keeps the process alive, because `ProcessContext::__destruct()` kills it); relays the child's stdout and stderr;
starts `WorkerMessageReader`; and tells the listener the worker spawned. The reader runs before the first byte is
sent.

`BrokerWorkerEvents::workerSpawned()` sends, in order: `Bootstrap` (built by `BootstrapMessageFactory`: protocol
version, worker ID, time zone, location, the slot's apps, `WorkerSettings`, `StoreSettings`, known and paused app IDs,
Stewart's user ID), `RegistrySnapshot`, `StateSnapshot`, and `HaConnectionLost` if Home Assistant is down. Both
snapshots are encoded once per revision and shared by every worker.

The broker does not block on `WorkerReady`. `armReadyDeadline()` kills a worker still starting after
`supervision.ready_timeout`; `WorkerReadyHandler` cancels the deadline and records which apps failed.

## Routing

**From workers.** `Broker/WorkerMessageReader` runs one fiber per worker. A clean EOF and a broken channel both end in
the gone callback; an undecodable frame is logged and skipped. Each message goes to
`Broker/Message/WorkerMessageDispatcher`, which looks up the one handler for its class (19 `WorkerMessageHandler`s:
subscribe, unsubscribe, service call, event fire, history, publish, MQTT publish, pong, ready, app failed, log record,
the exposure and registry requests). A handler that throws is logged per message.

**From Home Assistant.** `Broker/BrokerHaEvents` receives the session's callbacks:

- A state update goes to `EventRouter::broadcastStateUpdate()`: encoded once, sent to every live worker. State
  subscriptions never reach the broker; each worker matches them against its own cache.
- An event schedules a registry refresh when it is a `*_registry_updated` event, then is routed as `EventFired` to the
  workers whose subscriptions match, with their subscription IDs.
- Trigger fires, topic messages and MQTT messages are routed the same way, by trigger key, topic or filter.

`Broker/Message/SubscribeHandler` answers every `Subscribe` with a `SubscriptionAck`, refusing worker-local kinds and
bad selectors. `Broker/SubscriptionRegistry` wraps `Dispatch/SelectorIndex`: exact IDs in a hash table, patterns
matched once per entity ID and memoised (bounded), so a pattern picks up entities created later.

## The outbox

`WorkerHandle::send()` never suspends: it enqueues into `Broker/WorkerOutbox` and kicks a single flush fiber, which
keeps writes ordered. What happens on enqueue depends on the message's `OutboxDelivery`:

| Delivery | Messages | When the buffer is full |
|---|---|---|
| `Droppable` | `EventFired`, `TriggerFired`, `TopicMessage`, `MqttMessageDelivery` | The oldest is dropped beyond `worker_event_buffer` |
| `LatestOnly` | `Ping`, `RegistrySnapshot` | Replaced in place |
| `StateSegmentBoundary` | `StateSnapshot`, `StateResynced` | Resets coalescing |
| `Plain` | Everything else | Always kept, in order |

Consecutive state updates leave as one `StateUpdateBatch` of at most `worker_state_batch`. A burst that fits in one
batch goes out whole. Once a full batch is queued, further updates coalesce per entity: the first queued update stays,
and the latest replaces the tail. The update carries no `from`, so the merged tail is the latest shared encoding.
Drops are logged on a throttled schedule.

## Service calls

`Broker/Message/ServiceCallRequestHandler` runs each call through `Broker/HaCallSlots::runBounded()`. Over
`ha_calls.max_in_flight` or the per-worker limit, the call is refused with `Overloaded`. Otherwise it runs in its
own fiber and always answers on the same handle it came from: `ServiceCallResult`, or `RequestFailed` with the
exception's class, reason and context. Under dry run it logs and answers with a `dry-run:` context; while Home
Assistant is disconnected it fails at once as unreachable. The broker keeps no map of calls; the reply carries the
correlation ID.

## Supervision

- **Watchdog.** `Broker/WorkerWatchdog` sends a `Ping` every `ping_interval`. A worker that misses
  `unresponsive_after` probes is killed; `PongHandler` warns when the event-loop lag passes `lag_threshold` and
  records each app's activity.
- **A worker dies.** `WorkerPool::onWorkerGone()` ignores handles that are not live (displaced or shutting down),
  terminates the handle, and tells the listener, which removes the worker's subscriptions and orphans its exposures
  synchronously, before any respawn. Correlation and subscription IDs restart per process, so late cleanup would
  route a stale ID to a new handler.
- **Restarts.** `WorkerRestartPolicy` spends a `RestartBudget` with backoff; an exhausted budget quarantines the slot.
  `worker:restart` resets the budget, stops the worker gracefully, and respawns it.
- **Reload.** `Broker/Reload/ReloadCoordinator` accepts requests once the workers have started (SIGHUP, the `reload`
  control frame, `POST /api/reload`). `ReloadCheck` runs the hidden `reload:check` command through the project's
  `vendor/bin/stewart` in a child process with Xdebug off, because the broker cannot load app classes again; it
  answers with a `ReloadCheckReport` (the discovered apps, unmarked classes, errors and a fingerprint of the config
  file). `ReloadPlanner` reads the config again with the launch's `--config`, `--only` and `--log-level`, refuses a
  config file changed since the check, resolves the catalog, plans the slots, and lets `ConfigChangeClassifier` name
  every setting outside `apps` and `workers` that changed. `ReloadApplier` replaces `AppTopology`, the config pauses,
  the app metrics, the exposures of removed apps and the reconciler's grace, then calls
  `WorkerPool::applyWorkerSlots()`: every worker in the new plan is replaced (a starting one once live, a waiting or
  quarantined one at once), a worker left without apps is retired, and a new one is started. These restarts reset
  the budget but are not counted. `ReloadState` feeds the `reload` row of the snapshot.
- **Watch.** With `run --watch`, `Broker/Reload/SourceWatcher` scans every second. `SourcePathFinder` lists the
  project's `autoload` PSR-4 directories from `composer.json`, `services.php` and the config file; `SourceScanner`
  fingerprints them by path, mtime, size and inode (no inotify: it does not cross Docker Desktop bind mounts).
  `SourceChangeTracker` reports a change once two scans agree on it, and the watcher asks for a reload; a refused
  request is asked again on the next scan. `.env` and `composer.lock` only log a warning.
- **Reconnect.** When the connection drops, `BrokerHaEvents` broadcasts `HaConnectionLost`. The session retries the
  same establish routine; on success every worker gets a `RegistrySnapshot` and a `StateResynced`.

## Shutdown

`Broker/SignalHandlers` installs unreferenced SIGTERM, SIGINT and SIGHUP watchers. The first SIGTERM or SIGINT stops
the run; a second one while stopping kills the workers at once. SIGHUP asks for a reload. In Git mode the entrypoint
traps all three and forwards them to the daemon.

`BrokerRun::stopRun()` moves to Stopping and runs each step even if an earlier one fails: stop the watchdog; shut down
the pool (send `Shutdown` with the grace period, await exits for grace plus one second, kill the rest); close the Home
Assistant session; close MQTT. Signal handling needs `ext-pcntl`, which `stewart doctor` checks.

In Git mode, `Broker/Deploy/ReleasePoller` prepares a new commit, runs `stewart check` on it, stages it, and calls
`BrokerRun::stopToRestart()`. The run ends with `RestartRequested`, the process exits 75, and the entrypoint switches
releases and starts again.

## Control socket and HTTP

`Control/ControlPlaneFactory` returns a disabled plane when `control.listen` is off. `Control/Server/ControlServer`
listens with a restrictive umask on an unreferenced server, so it never keeps the loop alive, and warns when the
address is not loopback. `Http/HttpListenerFactory` builds the probe listener (`ProbeRequestHandler`) and the admin
listener (`AdminRequestHandler`) when their addresses are set. See [Control protocol](../control-protocol.md).
