---
title: FAQ
description: Short answers to common questions about Stewart.
sidebar:
  order: 2
---

**Do I need to know PHP?**
Some. Apps are small PHP classes; the [first app](../start/first-app.md) and the [recipes](../recipes/index.md) are a
good place to start. You do not need to install PHP; everything runs in Docker.

**Does it replace Home Assistant automations?**
It sits next to them. Use whichever fits: YAML or the UI for simple automations, Stewart for logic you want to test,
reuse and keep in Git.

**Does it run inside Home Assistant, as an add-on?**
No. Stewart runs in its own container and connects to Home Assistant over the network, like the Home Assistant app
does. It can run on the same machine.

**Which Home Assistant versions does it support?**
Current releases. The Stewart integration, needed only for [exposed entities](../guides/exposed-entities.md), needs
Home Assistant 2026.4 or newer.

**Can I use Composer packages?**
Yes: `make sh`, then `composer require …`, and register services in `services.php`. Prefer non-blocking libraries
(amphp) for network calls; a blocking HTTP client holds up every app on the same worker while it waits.

**How many apps can it run?**
Many. Apps that wait for events cost almost nothing. Stewart spreads them over several worker processes, one per CPU
core (up to 4) by default.

**What happens when Home Assistant restarts?**
Stewart reconnects on its own and catches up on the states that changed. Schedules keep running, and sun times are
computed by Stewart itself. Triggers that would have fired during the outage are lost.

**What happens when Stewart restarts?**
Apps start again from `initialize()`. What they stored in the [store](../guides/persistence.md) is still there;
properties are not.

**Can I run two Stewart installations?**
Against different Home Assistants, yes. Against the same one, they must run different apps, or each automation runs
twice, and each needs its own `expose.instance`.

**Can I test without Home Assistant?**
Yes. Tests run against fakes on a virtual clock; see [Testing apps](../guides/testing.md).

**Is my data sent anywhere?**
No. Stewart talks only to your Home Assistant, your Valkey, and the MQTT server you configure.

**What does it cost?**
Nothing. Stewart is open source under the MIT license.
