---
title: Glossary
description: The words these docs use, in one place.
sidebar:
  order: 3
---

| Term | Meaning |
|---|---|
| **Action** | Home Assistant's name for a service, such as `light.turn_on`. Also an [exposed action](../guides/exposed-entities.md#actions): a service your app answers. |
| **Admin API** | An optional HTTP port to list, pause and resume apps, and read [metrics](../reference/metrics.md). |
| **App** | One automation: a class in `apps/` with `#[Automation]` that implements `App`. |
| **App ID** | The name in `#[Automation(id: '…')]`. It names the app in logs, settings and stored data. |
| **Broker** | Stewart's main process. It holds the connection to Home Assistant and passes events to the workers. |
| **Control socket** | How `stewart status` and the other commands talk to a running Stewart. It needs the control token. |
| **Dry run** | A mode that logs service calls instead of sending them, to try apps against your real Home Assistant. |
| **Entity filter** | A way to pick entities by area, floor, label or device. See [Areas, floors and labels](../guides/registry.md). |
| **Exposed entity** | An entity your app creates in Home Assistant, such as a sensor or a switch. Needs the Stewart integration. |
| **Handler** | The function you pass to `subscribe()` or to a schedule. Stewart calls it for each event or run. |
| **Harness** | `AppHarness`, which runs an app in a test against fakes and a virtual clock. |
| **Integration** | The Stewart custom integration for Home Assistant, installed through HACS. Only exposed entities need it. |
| **Operator** | A method between `watchStateChanges()` and `subscribe()` that shapes a stream, such as `whenChangedTo()` or `debounce()`. |
| **Option** | A constructor parameter of an app that `stewart.yaml` can set, under `apps.<id>.options`. |
| **Reason** | The enum on every Stewart exception that says exactly what failed, such as `StoreError::TypeMismatch`. |
| **Selector** | The entity IDs a method listens to: an exact ID, a pattern with `*`, or a list. |
| **Service** | Two meanings. In Home Assistant, something you call, such as `light.turn_on`. In `services.php`, one of your own classes that apps receive in their constructor. |
| **Skeleton** | The template a new project starts from, `stewart-php/skeleton`. |
| **Store** | Storage that survives restarts, kept in Valkey. Each app has its own; the global store is shared. |
| **Stream** | What `watchStateChanges()`, `watchEvents()` and similar methods return: a sequence of events to shape with operators and `subscribe()` to. |
| **Trigger** | A Home Assistant trigger, such as a zone or a template, used from an app with `watchTrigger()`. |
| **Typed classes** | The PHP classes `make generate` writes into `generated/` for your entities and services. |
| **Valkey** | The open-source, Redis-compatible database that keeps the store's data. |
| **Worker** | A process that runs apps. Apps on different workers cannot take each other down. |
