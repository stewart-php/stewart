---
title: 'Client and testing'
description: 'The Home Assistant WebSocket client, and the test harness as a second HaContext.'
---

## The client

Paths in this section are relative to `packages/client/`. The client knows nothing about brokers, workers or apps;
the broker and codegen both use it.

**Two layers.** `Connection/HaConnection` owns the socket: connecting, authenticating, sending a command and awaiting
its answer, and delivering events. `HaClient` puts typed commands on top: `getStates()`, `callService()`,
`subscribeEvents()`, the registry reads and the rest. `HaClient::fromConnectionConfig()` or `HaClientFactory` builds
it.

**Connecting.** `HaConnection::connect()` races the connect against `connect_timeout` (a composite cancellation),
opens the socket with a heartbeat (amphp's `PeriodicHeartbeatQueue`, from `heartbeat_interval` and
`heartbeat_missed_limit`), authenticates, and only then starts `receiveLoop()` in its own fiber. The parser skips
amphp's UTF-8 check because `json_decode()` repeats it, so a frame with invalid UTF-8 is logged and dropped as not
JSON, and the connection stays up.

**Authenticating.** Home Assistant speaks a small protocol before the command protocol: `auth_required`, `auth`,
`auth_ok`. `authenticate()` reads those messages directly, before the loop runs. An unexpected first message is
`UnexpectedGreeting`; a refused token is `TokenRejected`, which is fatal.

**Commands and events.** `MessageCorrelator` issues command IDs from 1 on each connection. `sendAndAwaitResponse()`
applies `command_timeout` and forgets the ID in `finally`. `dispatchMessage()` knows two message types: a `result`
completes a waiting command, an `event` goes to the handler registered under its subscription's command ID, through
`EventDelivery`, a bounded queue (a full one is `EventBacklogExceeded`).

**Losing the connection.** `handleConnectionLost()` fails every waiting command and calls the disconnect callback.
The client does not reconnect; the broker's `WebsocketHaSession` does.

**Errors.** Failures are `HaClientException` with a `HaClientError` reason. `callService()` maps them to
`ServiceCallException` (rejected, timed out, unreachable); subscribing without administrator rights is
`AdministratorRequired`. URLs are `HomeAssistantUrl` values, whose string form is redacted, so a token in a URL never
reaches a log.

**Codegen** uses the same client: `codegen/src/Snapshot/HaClientSnapshotFetcher` connects, refuses unless Home
Assistant is running, reads states, the registries and services, and closes.

## The test harness

Paths here are relative to `packages/testing/`.

`HaContext/RecordingHaContext` is the second implementation of `HaContext`. Its streams use the same operators from
`stewart-php/support`, over a `PushSource` instead of a dispatcher, so a test exercises the real `whenChangedTo()`,
`debounce()` and the rest. `seedState()` and `pushState()` drive it; service calls are recorded as
`RecordedServiceCall`s and answered from stubs.

`App/AppHarness::startApp()` builds the app the way a worker does: options by parameter name, `#[GlobalStore]` and
`#[PeerStore]`, provided services, a fake for each injectable contract type. Like the worker, it holds deliveries
until `initialize()` returns. `advanceTime()` moves `VirtualClock` and runs every timer, schedule and wait that falls
due, in order; handlers run in fibers through `Async/HandlerFibers`.

That both implementations share the operator code is what makes a harness test meaningful: only the transport is
fake.
