---
title: 'Code walkthrough'
description: 'The code in the order it runs, from bin/stewart to an app handling a state change.'
---

These pages follow the code in execution order, naming the classes and explaining why each does what it does.
[Architecture](../architecture.md) describes the shape and the invariants; this is where they surface in the code.
Paths are relative to `packages/runtime/` unless they say otherwise.

```
bin/stewart
  → ConsoleLauncher → ConsoleKernel → StewartApplication → RunCommand
      config (ConfigLoader), apps (AppDiscovery, AppCatalogResolver)
  → BrokerKernel → BrokerLifecycle::run()
      store probe, control/HTTP/MQTT, WebsocketHaSession::open(), WorkerStartup
  → bin/worker.php (one process per worker) → WorkerKernel → WorkerSession
      construct apps, await StateSnapshot, initialize apps, WorkerReady
  → a state change: HaConnection → WebsocketHaSession → EventRouter → WorkerOutbox
      → StateUpdateBatch → StateCacheSync → LocalDispatcher → SubscriptionQueue → your handler
```

| Page | Covers |
|---|---|
| [Console and configuration](console.md) | The entry point, the container profiles, configuration loading, app discovery, `run` |
| [The broker](broker.md) | Boot, connecting to Home Assistant, spawning workers, routing, the outbox, service calls, supervision, shutdown |
| [The worker](worker.md) | Worker boot, building apps, `HaContext`, streams and dispatch, a service call end to end, schedules, pause, logging |
| [Client and testing](client.md) | The Home Assistant WebSocket client, and the test harness as a second `HaContext` |
