---
title: How it fits together
description: The broker and its workers, and the three consequences of that split.
sidebar:
  order: 2
---

```
Home Assistant
      │  one websocket
      ▼
  Broker process
   - owns the socket, auth and the authoritative state cache
   - routes events to workers by subscription
   - correlates service calls
   - writes one ordered log stream
      │  amphp/parallel channels
      ├──────────────┬──────────────┐
      ▼              ▼              ▼
  Worker 0       Worker 1       Worker 2
  full state cache + apps
```

Workers never talk to Home Assistant. An app calling `callService()` sends a
message and suspends its fiber until the result comes back, so it reads like a
local call. Reads never leave the process, because every worker holds a full
cache of entity state.

Three consequences worth knowing:

- **Every worker receives every state change**, subscribed or not — a full
  state cache can only stay fresh if it sees everything. Routing decides who gets
  *told*, never who gets *updated*.
- **Selectors are values, not closures.** The channel carries JSON frames,
  which cannot hold a closure, so an event or topic selector routes in the
  broker and every predicate runs in the worker.
- **A worker restart is cheap.** The broker keeps the connection and the state
  cache, so nothing reconnects and nothing resyncs.

The developer-facing version of this, with the invariants that hold it
together, is in [Architecture](../../dev/architecture.md).
