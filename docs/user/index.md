---
title: 'Stewart documentation'
description: 'Write Home Assistant automations in PHP.'
---

| | |
|---|---|
| **[Guide](guides/quickstart.md)** | Installing, configuring, and writing an automation. |
| **[Architecture](../dev/architecture.md)** | Process model, packages, the API surface, and the invariants that hold it together. |
| **[Code walkthrough](../dev/walkthrough.md)** | The execution path in the order it runs, from `bin/stewart` to an app handling an event. |
| **[Channel protocol](../dev/protocol.md)** | The broker↔worker message set, the sequences that use it, and how it fails. |

---

## Diagrams

Sources are PlantUML in `docs/dev/diagrams/`. They render to SVG at build time
rather than being committed, so after editing a `.puml`:

```bash
make docs-diagrams
```

That runs PlantUML in a container — like everything else here, nothing is
installed on your host. `style.puml` is a shared include; it produces no image
of its own, so PlantUML warning about that is expected.

| Diagram | Shows |
|---|---|
| `01-context` | Stewart between Home Assistant and your automations |
| `02-processes` | Broker and workers, and what each owns |
| `03-packages` | Package dependencies and the rule about `contracts` |
| `04-contracts` | The public API surface |
| `05-protocol` | The twenty channel messages |
| `06-boot` | Startup through `WorkerReady` |
| `07-state-event` | A state change reaching an app |
| `08-service-call` | The correlated call and its fiber suspension |
| `09-pubsub` | App-to-app messaging across processes |
| `10-shutdown` | Graceful shutdown |
