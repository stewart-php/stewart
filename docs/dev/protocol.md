---
title: 'Channel protocol'
description: 'The broker-to-worker message set, the sequences that use it, and how it fails.'
sidebar:
  order: 3
---

What travels between the broker and a worker, and the sequences that use it.
Read [Architecture](architecture.md) first for the shape this sits inside.

---

## The wire

Messages are `final readonly` PHP objects in `Ipc\Message`. Each one crosses
the `amphp/parallel` channel as one JSON frame:

```json
{"t": "subscribe", "m": {"subscription_id": "w0:3", "app_id": "demo", "kind": "event", "selector": {"kind": "exact", "value": "zha_event"}}}
```

`t` is the tag `IpcCodec::TAGS` assigns to the message class it
carries. `m` is the message as `WireMapper` writes it. Each key is the
snake_case of a constructor property, and instants and durations are integer
microseconds under a `_us` key (`lost_at_us`, `grace_us`). `IpcCodec` refuses a
message class that has no tag. `ProcessContext` still wraps each frame string in
`serialize()`, because it hard-wires `NativeSerializer`. What reaches
`serialize()` is a string, never an object graph.

State is encoded once for every worker.
`StateSnapshot`, `StateResynced` and `StateUpdateBatch` hold `EntityStatesFragment` and
`StateUpdatesFragment` fragments, which cache their JSON on the broker. The frame
splices that JSON in unescaped. On the worker, the same classes hold the decoded
lists. Every worker decodes every change, so `StateUpdate`, `EntityState` and
`EventContext` skip reflection and go through hand-written converters in
`Ipc/Wire`. Their keys are the reflected ones, and `HandWrittenShapeParityTest`
fails when the two differ.

The vocabulary belongs to the wire. A message may use only contracts, the
`Runtime\Model` enums and DTOs, and `Ipc` types such as `WorkerApp`,
`WorkerSettings` and `StoreSettings`. A phpat rule enforces
this, so changing a config value object is never a protocol change.

The rules that follow:

- **`Bootstrap` carries the protocol version.** A worker whose
  `IpcCodec::PROTOCOL_VERSION` differs stops with `TransportError::ProtocolMismatch`
  before it reads anything else. Any change to a message's shape bumps it; the IPC
  goldens fail until it does.
- **A frame that cannot be decoded is dropped.** The reader logs its tag and
  carries on. An undecodable `Bootstrap` stops the worker.
- **Selectors are one closed type.** `Selector` is a final value object whose
  kind is exact, glob, regex or any-of, and `*` crosses as `any`. A string
  becomes a glob when it has `*` or `?`, and an exact match otherwise; a regex
  is only ever built with `Selector::regex()`.
- **Payloads must be JSON.** Topic payloads refuse objects and non-finite
  floats up front. Service data that cannot be encoded fails the call as
  `Rejected`.
- **Log context is flattened before it is sent,** and **a `Throwable` is never
  sent as itself.** `AppFailed` carries a flattened trace and the
  `ExceptionDetails` of a Stewart exception: `{class, reason, context}`, with
  the reason as its enum value.

Sends are drained one at a time in a single fiber per worker. That is what keeps
channel writes ordered and stops a large snapshot interleaving with the events
queued behind it.

The `Ping`/`Pong` loop-lag figure assumes **both processes share a clock** —
true because they are the same host by construction. The delay between the
broker stamping a ping and the worker answering is then a usable proxy for how
backed up that worker's event loop is.

---

## The message set

![Protocol](diagrams/05-protocol.svg)

Forty-one messages, closed by the `BrokerMessage`, `CorrelatedReply` and
`WorkerMessage` marker interfaces and by the `IpcCodec::TAGS` tags.

### Broker → Worker

| Message | Purpose |
|---|---|
| `Bootstrap` | The protocol version, identity, timezone, Home Assistant's location (behind `SunCalendar`), the app list, the worker settings (log level, call and initialize timeouts, subscription buffer, where `services.php` is, the generated namespace), the store settings, every discovered automation's ID and the Home Assistant user the token belongs to (behind `HaContext::wasCausedByStewart()`). First message a worker sees. |
| `RegistrySnapshot` | Home Assistant's areas, floors, labels, devices and entity registry with a revision, **encoded once** (`RegistryFragment`). Sent before `StateSnapshot` to a new worker, before `StateResynced` after a reconnect, and to every live worker after a registry refresh. The worker keeps the highest revision. |
| `StateSnapshot` | Whole-world state, **encoded once** and shared by every worker (`EntityStatesFragment`). Also means "you are new: initialize your apps". |
| `HaConnectionLost` | Home Assistant is gone, with the moment and the reason. Service calls now fail in the worker, and apps hear `ConnectionLost`. Also sent right after `StateSnapshot` to a worker bootstrapped during an outage. |
| `StateResynced` | The rebuilt snapshot after a reconnect. The worker diffs it against its state cache, replays the differences as reconstructed state changes, then tells apps `ConnectionRestored`. Nothing is initialized again. |
| `StateUpdateBatch` | One or more state updates, in order, each encoded once (`StateUpdatesFragment`). An update carries the new state, not the previous one. Sent to every live worker; each rebuilds the change from its cache and matches it against its own subscriptions. |
| `EventFired` | Any other Home Assistant event — `zha_event`, a custom one — plus the subscription IDs that matched. Sent only to workers that asked. |
| `ServiceCallResult` | Answer to a `ServiceCallRequest`, with the call's `EventContext` (`dry-run:…` under dry run). Always sent, success or not. |
| `EventFireResult` | Answer to an `EventFireRequest`, with the fired event's `EventContext`. |
| `HistoryResult` | Answer to a `HistoryRequest`: the entity's recorded states, oldest first. |
| `RequestFailed` | The failure answer to any correlated request: a service call, event fire, history query, registry edit or exposure. It carries the exception class, reason and context, and the worker rebuilds the exception the request expects. `Overloaded` means the broker refused the call because `service_calls.max_in_flight[_per_worker]` is already reached. |
| `TopicMessage` | An app-to-app message, routed. |
| `MqttMessageDelivery` | A message from the MQTT server, with the subscription IDs whose filter matched its topic. Droppable, like `TopicMessage`. |
| `TriggerFired` | A Home Assistant trigger fired (`TriggerEvent`), with the subscription IDs that share its spec. Droppable, like `EventFired`. |
| `SubscriptionAck` | Confirms a registration, or says why it was refused. For a trigger, a refusal can also arrive later, when Home Assistant rejects the config. A refused subscription is cancelled in the worker. |
| `PausedAppsChanged` | The full set of paused apps, after a pause, resume or reset. Plain delivery, so it never overtakes deliveries queued before it. `Bootstrap` carries the same set at start. |
| `ExposedEntityCommanded` | Someone used an exposed entity in Home Assistant; the owning worker runs the app's command handlers and answers with `ExposedCommandAnswered`. |
| `ExposedEntitySynced` | Home Assistant reported an exposed entity's registry or restored state; the owning worker updates its handle. |
| `ExposeEntityResult`, `ExposureAcknowledged` | Answers to `ExposeEntityRequest` (with the restored snapshot) and to the other exposure requests. |
| `RegistryEntityUpdateResult` | Answer to a `RegistryEntityUpdateRequest`: the entry Home Assistant stored. |
| `Shutdown` | Dispose apps and return. |
| `Ping` | Liveness and loop-lag probe. |

### Worker → Broker

| Message | Purpose |
|---|---|
| `Subscribe` / `Unsubscribe` | Register or drop interest in Home Assistant events or topics, carrying a `Selector`. State and connection subscriptions never cross: the worker matches them itself. |
| `SubscribeTrigger` | Register interest in a Home Assistant trigger, carrying the `TriggerSpec` (trigger configs and variables). Dropped with the same `Unsubscribe`. |
| `WorkerReady` | Every app constructed, initialized and subscribed. Never sent once the worker is stopping, and the broker's `supervision.ready_timeout` restarts a worker that never sends it. |
| `ServiceCallRequest` | An app wants Home Assistant to do something. |
| `EventFireRequest` | An app fires an event on Home Assistant's bus. |
| `HistoryRequest` | An app wants one entity's recorded states over a fixed window. |
| `Publish` | An app-to-app message on its way to routing. |
| `MqttPublish` | A message for the MQTT server, with the publishing app's scope. |
| `ExposeEntityRequest`, `UpdateExposedEntityRequest`, `ReconfigureExposedEntityRequest`, `RemoveExposedEntityRequest` | An app exposes an entity, changes its state, replaces its configuration, or removes it. Correlated like a service call. |
| `ExposuresReleased` | An app stopped; the broker marks its exposed entities unavailable. |
| `ExposedCommandAnswered` | The app accepted or rejected a command, by its command ID. |
| `RegistryEntityUpdateRequest` | An app edits an entity registry entry through `RegistryEditor`. |
| `AppFailed` | An app threw, with the phase and a flattened trace. |
| `Pong` | Answers `Ping`, carrying the worker's event-loop lag, memory, one activity report per app (state, subscription and schedule counts, cumulative delivered, handled, dropped, schedule-run and publish counts, the last handled event and the next run, and one report per subscription and per schedule) and, when a store is configured, its health as the worker sees it. |
| `LogRecord` | A log line, to be written by the broker, tagged with its app. `trace` marks a line of a traced app, which passes whatever the log level says. |

`Bootstrap` groups what a worker is told into `WorkerSettings`, with no
defaults on the wire side, so a configured value always crosses as itself.
`logLevel` is the worker's threshold for forwarding records; an app whose
`WorkerApp.verbosity` names a level uses that one instead, louder or quieter, and
`verbosity.trace` turns on its `TRACE` lines.
The broker filters again by the record's app, so a reload that changes an
app's level applies on both sides. `callTimeout` is
`home_assistant.command_timeout` plus a fixed five seconds of IPC headroom, so
the worker's timeout is always strictly the outer one. `initializeTimeout` is
`supervision.initialize_timeout`, and the worker, not the broker, enforces it.
`generatedNamespace` is `codegen.namespace`, and the worker resolves the two
generated roots from it with `class_exists`, so a worker with no generated code
wires nothing. The store's DSN crosses as a sensitive string, and the worker
parses it again and resolves the backend from its scheme. With it comes
`available`, false when the worker starts during a store outage.

`servicesFile` is used too: the worker builds its
container from it. It travels rather than being derived because a worker cannot
work it out — its entrypoint path is relative to the runtime package, which sits
at a different depth under a path repository than under a vendor install.

`SubscriptionAck` exists because a silently-ignored subscription is the worst
failure mode in an automation system — nothing happening looks exactly like
nothing needing to happen.

An event or topic subscription takes effect once the broker has processed its
`Subscribe`; anything routed before that is not delivered. A state subscription
takes effect the moment it is registered, because the worker already receives
every change for its state cache.

---

## Status

The broker learns what apps are doing from the activity reports on each `Pong`, at the
watchdog's cadence; nothing else is asked of a worker. Reports carry counts and
states, never payloads or options. The broker adds each report's difference from
the previous one of the same worker, so its per-app totals keep growing across
restarts.

`stewart status` reads the result over the control socket, described in
[Control protocol](control-protocol.md).

A request the broker cannot answer (unknown frame, unknown or disabled app, unknown worker) gets
`request_failed` with the exception's reason value. Pausing a paused app, or
resuming a running one, answers `changed: false`; `reset_app` answers
`changed: true` when it removed a stored override; `restart_worker` answers
`changed: false` while the worker is already starting or stopping. `warning` is `null` unless the
change could not be stored. An app's `pause` is `null` while it
runs, otherwise `since` and `source` (`config`, `control` or `http`); a config pause
holds from daemon start. `config_pause_override` has the same shape and is set
only while a stored resume beats a config pause. An app's
`state` travels by value: `declared`, `constructing`, `constructed`,
`initializing`, `initialized`, `running`, `failed`, `skipped`, `disposed`,
`abandoned`, or `null` for an app that has not reported yet. An app's
`last_failure` carries `phase`, `class`, `message`, `origin`, `reason` (the
exception's reason value, `null` for a non-Stewart throwable) and `at`. The snapshot's
`store` is `null` without `persistence.url`, and otherwise `up`, `since`, `last_failure` and `last_failure_at`.

---

## Boot

![Boot](diagrams/06-boot.svg)

The ordering constraint worth internalising: apps are **constructed** on
`Bootstrap` but **initialized** only after `StateSnapshot` has populated the
state cache. Reading state inside `initialize()` is normal and must work.
`RegistrySnapshot` comes between the two, so the registry is loaded by then too.

The reader runs from before the first constructor, so a constructor may await a
`ServiceCallRequest` and get its answer. An app's schedules and subscriptions
stay dormant until that app's own `initialize()` returns; events matched in the
meantime are buffered under `subscription_buffer` and delivered in order once it
does.

The broker does not wait for `WorkerReady`: a worker gets every state change
from the moment it spawns, and `WorkerReady` only cancels its ready deadline.

Two `amphp/parallel` details that bite:

- Use `ProcessContextFactory` explicitly. `DefaultContextFactory` silently
  substitutes threads when `ext-parallel` is present — a different memory and
  failure model entirely.
- The entrypoint file must `return` a callable taking a `Channel`. Anything
  else fails with *"did not return a callable function"*. The Composer
  autoloader is already loaded (found by walking up), and the callable already
  runs inside a fiber, so it may await freely.

---

## A state change

![State event](diagrams/07-state-event.svg)

The broker sends every state change to every live worker as a `StateUpdate`: the
new state without the previous one. The worker takes `from` from its state
cache, applies the update and then matches the change against its own
subscriptions. There is no routing decision on the broker and no window in which
a subscription exists in the worker but not yet in the broker.

A worker's snapshot is queued before any update, and every update reaches every
live worker in order. So the cached state is the one Home Assistant replaced, and
`from` is what `getState()` returned just before the change.

Matching is memoised per entity ID in a `SelectorIndex`, the same one the
broker uses for events and topics. Entity IDs are a bounded, slow-changing set,
so fifty patterns across two thousand entities costs a few milliseconds once
and hash lookups thereafter. Exact-ID subscriptions — the overwhelming majority
— skip pattern matching entirely. The cache is also what makes pattern
subscriptions pick up entities created later: an unknown ID is evaluated on
first sight and remembered. Adding or removing a subscription clears it.

A worker with no subscriber still applies every change. That is the standing
cost of the full-state-cache decision.

Each subscription has its own queue and one fiber that runs its handler, so one
subscription handles its events one at a time while different subscriptions run
concurrently. A handler that awaits a service call holds back only the later
events of its own subscription.

---

## A service call

![Service call](diagrams/08-service-call.svg)

Two correlation maps, no cleverness: the worker maps `correlationId → Future`,
the broker maps `correlationId → worker` and `haMessageId → correlationId`. IDs
are `{workerId}:{n}`, unique across the daemon without coordination.

The fiber suspension in the middle is the whole "reads like a local call"
property.

The failure mode to design against is a reply that never arrives, which leaves
that fiber suspended for the life of the process with nothing in the logs. So
the broker answers every request including failures, and the worker wraps every
await in a `TimeoutCancellation` as a backstop.

Every write to Home Assistant counts against one in-flight budget, `HaCallSlots`,
bounded by `ha_calls.max_in_flight` and `max_in_flight_per_worker`: service
calls, fired events, history queries and registry edits alike. A fired event is a
write like a service call, so it shares the budget, the per-app call counts and
dry run; a burst of one can refuse the other. On the worker side, one
`PendingRequests` registry holds every correlated request.

## A history query

The same shape as a service call: one correlation ID from the same per-worker
sequence, one reply per request, the same worker-side timeout and the same
in-flight budget. The worker
turns `HistoryQuery::lastFor()` into a fixed window with its own clock before
sending, so the broker never sees a relative time. The reply carries only the
states; the worker already knows the entity and the window.

---

## App-to-app messaging

![Pub/sub](diagrams/09-pubsub.svg)

Named pub/sub, using the same selector machinery as entities — `house.*` works.
Unlike state changes, a topic message goes only to workers holding a matching
subscription; there is no state cache to keep fresh.

Fire-and-forget, with no reply channel. Request/response between apps was
considered and rejected for now: it needs correlation IDs, timeouts, and a
deadlock story for two apps that ask each other. An app needing an answer models
it as a second topic. Adding it later is two more message types, not a redesign.

---

## MQTT

The broker holds the only MQTT connection, as it holds the only Home Assistant
one. `stewart-php/mqtt` provides it behind the `MqttLink` port; without
`mqtt.url` the broker uses a disabled link and workers are told
`mqtt_enabled: false`, so an app that asks for `Mqtt` is not started.

An app's `watchMessages('home/+/temp')` is a `Subscribe` of kind `mqtt` with an
`mqtt_filter` selector. The broker counts subscribers per filter and sends
`SUBSCRIBE` to the server only for the first and `UNSUBSCRIBE` after the last.
Messages from the server are matched against the same registry and sent as
`MqttMessageDelivery`. Payloads are bytes, so they cross the IPC wire base64
encoded.

`MqttPublish` is fire-and-forget for the app. While the server is unreachable,
QoS 0 messages are dropped and logged; QoS 1 messages wait in a buffer of
`mqtt.outbound_buffer`, oldest dropped first, and are sent again after each
reconnect until the server acknowledges them. Sessions are clean: after a
reconnect the broker subscribes every active filter again.

---

## Triggers

An app's `watchTrigger()` is a `SubscribeTrigger` carrying the trigger configs
and variables. The broker keys it by a hash of the canonical JSON (map keys
sorted, lists in order) and counts subscribers per key, as it does for MQTT
filters: Home Assistant gets one `subscribe_trigger` for the first subscriber and
one `unsubscribe_events` after the last. When it fires, the broker sends
`TriggerFired` to every worker holding a subscription with that key.

Home Assistant's subscription ids belong to one connection, so after every
connect the broker subscribes each live spec again, right after
`subscribe_events` and before seeding states. A trigger that fired during the
outage is not replayed, and fires that arrive while the state cache is rebuilt
are dropped, like events. If Home Assistant refuses a spec, at first or on a
later reconnect, every subscriber gets `SubscriptionAck(false, reason)` and is
removed; the connection itself carries on.

---

## Shutdown

![Shutdown](diagrams/10-shutdown.svg)

SIGTERM or SIGINT → stop probing and restarting workers → `Shutdown` to each worker →
`dispose()` → the worker **returns** → `join()` with a grace period →
`close()` only as the force-kill, because `ProcessContext::close()` sends a kill
signal outright.

Signal handling requires `ext-pcntl`. Without it Revolt's `onSignal()` throws
and shutdown cannot be graceful at all, which is why `make doctor` checks for
it.

---

## Backpressure

Each `WorkerHandle` holds a bounded outbound buffer (1000 by default,
`worker_event_buffer` in `stewart.yaml`). Topic messages and Home Assistant
events share it; when it fills, the **oldest** of them is dropped, a warning
names the worker and the drop count, and the count travels in the worker's
outbox status. Every other message is kept, except that an unanswered probe is
replaced by the next one rather than queued behind it. State changes are not counted against the
buffer: they coalesce per entity instead, so a slow worker sees fewer of them
but never loses the latest.

A queued `RegistrySnapshot` is replaced by a newer one, like an unanswered
probe, so a slow worker gets only the latest registry.

Consecutive queued state updates leave as one `StateUpdateBatch` of at most
`worker_state_batch` (256 by default). Coalescing starts only once a full batch
is queued, so a burst that fits in one batch reaches the worker step by step.
It keeps the first queued update of an entity where it is and replaces every
later one with the latest, at the tail. The worker applies the first one before
the tail, so the tail's `from` is the state the first one reached. on → off → on
therefore still arrives as two edges, and the merged update never overtakes an
event queued before it.

Inside the worker, each subscription queues up to `subscription_buffer` events
(100 by default) while its handler is busy. When a state-change queue is full, it
first drops the oldest change that a newer change of the same entity supersedes,
and the newer one starts from the dropped one's state. A quiet entity's only
change therefore survives a chatty neighbour. If no entity has two queued
changes, and for every other kind of event, the oldest is dropped. A burst of
state changes, one `StateUpdateBatch` or a resync, is queued whole: older queued
events make room for it. Drops are counted per app and logged as a sampled
warning.

Dropping oldest rather than newest is the right way round for automations: a
stale state change is the least valuable thing in the queue. Blocking instead
would let one slow app halt every other automation in the house, which defeats
the point of the isolation. Unbounded buffering is an OOM waiting for a slow
app.

---

## Reconnect

The connection drops, `HaConnection` fires its disconnect hook, and the broker
retries forever with capped backoff. A worker that dies meanwhile is restarted
on its usual backoff. It is bootstrapped with the last snapshot, told
`HaConnectionLost` straight after, and resynced with everyone else.

Each attempt closes any half-open socket, connects, **subscribes first**, then
calls `get_states`. Changes arriving between the two are buffered by entity id
and drained onto the cache straight after the seed. Seeding before subscribing
would lose every change in that window permanently.

Every live worker hears `HaConnectionLost` when the connection drops. From
then on `isConnected()` is false and `callAction()` fails at once as
`Unreachable`. Schedules keep running.

Each attempt also reads the five registries after `get_states`. The seed, the
re-encode and the broadcast contain no suspension point, so no worker can see a
half-rebuilt cache. Every live worker then gets one `RegistrySnapshot` and one
`StateResynced`, in that order, so reconstructed changes match entity filters
against the current registry. Each one compares the new snapshot with its state cache, swaps it
in, and queues one reconstructed `StateChange` (`origin: Resync`) per entity
that differs, including entities that appeared or disappeared. Then it
dispatches `ConnectionRestored` to the apps that listen. A flap during the
outage shows as one edge, because only the two ends are known.

---

## Failure modes

| What happens | How it surfaces | What the broker does |
|---|---|---|
| Worker segfaults or is `kill -9`'d | `receive()` throws `ContextException` | Drops its subscriptions, then respawns it after a backoff |
| A worker keeps dying | `restart_attempts` spent inside `restart_window` | Quarantines it and logs at `error`, naming the automations now down |
| Worker stops responding | `Pong` lag climbs, or nonces go unanswered | Warns, naming the worker and its apps — usually blocking code |
| App throws in `initialize()` | `AppFailed` with phase `Initialize` | App is dropped and its subscriptions cancelled |
| App throws in a handler | `AppFailed` with phase `Handler` | Logged; the app keeps running |
| An event or topic subscription the broker cannot route | `SubscriptionAck { accepted: false }` | Worker logs an error rather than receiving nothing forever |
| Home Assistant restarts | `HaClientError::ConnectionDropped` | Sends `HaConnectionLost`, reconnects with capped backoff, rebuilds state, sends `StateResynced` |
| The store stops answering | A worker's call times out or finds it unreachable | Nothing: that worker fails store calls at once for `persistence.recovery_interval`, then tries again; its next `Pong` reports the store down |
| A frame cannot be decoded | `TransportError::UndecodableFrame` | The reader logs the tag and drops the frame; an undecodable `Bootstrap` stops the worker |
| Broker and worker disagree on the protocol | `TransportError::ProtocolMismatch` on `Bootstrap` | The worker stops; the restart budget quarantines it if it keeps happening |
| Home Assistant rejects the token | `HaClientError::TokenRejected` | Fatal: stops every worker cleanly and exits non-zero |
| The token's user is not an administrator | `HaClientError::AdministratorRequired` on `subscribe_events` | Fatal the same way; retrying cannot grant the privilege |

---

## Testing it

`packages/runtime/tests/Integration/Protocol/WorkerProtocolTest.php` boots a
worker in-process over an in-memory transport pair and drives the whole protocol
from the test, standing in for the broker. Frames still go through the codecs. It
runs with `make test-integration` and needs neither Home Assistant nor Valkey.

This is the test that earns its keep: the entrypoint contract, the
initialize-after-snapshot ordering, selector serialization, service-call
correlation and clean shutdown are all invisible to testing a single class.

`make test-process` runs `packages/runtime/tests/Process/WorkerProcessSmokeTest.php`: a real
worker process over a real pipe, and a store in Valkey that outlives it.
