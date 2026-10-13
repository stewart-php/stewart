---
title: Home Assistant automations in PHP
description: Write Home Assistant automations in PHP.
template: splash
hero:
  title: Stewart
  tagline: Write your Home Assistant automations as plain PHP classes. Test them like any other code, and run them in Docker next to Home Assistant.
  actions:
    - text: Get started
      link: /docs/start/install/
      icon: right-arrow
      variant: primary
    - text: View on GitHub
      link: https://github.com/stewart-php/stewart
      icon: external
      variant: minimal
---

Stewart connects to your Home Assistant and runs automations you write in PHP. Each automation is a small PHP class, which Stewart calls an **app**:
it watches entities, reacts to events and schedules, and calls Home Assistant services. You keep the automations in
your own Git repository, check them with PHPStan, and test them with PHPUnit, without a running Home Assistant.

This is a complete automation. It turns the porch light on at sunset and off at 23:00:

```php file=../examples/apps/PorchLight.php
```

## How it runs

```
Home Assistant
      │  one connection
      ▼
   Stewart  ── keeps a copy of every entity's state
      │
      ├── worker ── your apps
      └── worker ── your apps
```

Stewart keeps one connection to Home Assistant. Your apps run in separate worker processes, so an app that crashes
or hangs cannot take the others down. [How it works](guides/how-it-works.md) explains what that means for you.

## Where to go next

| | |
|---|---|
| **[Install Stewart](start/install.md)** | Create a project, connect it to Home Assistant, and run it. About 15 minutes. |
| **[Your first app](start/first-app.md)** | Build, test and run the porch light above, step by step. |
| **[Project tour](start/project-tour.md)** | What each file in your project is for. |
| **[Recipes](recipes/index.md)** | Complete automations to copy: motion light, heating by presence, door reminders and more. |
| **[Guides](guides/state-changes.md)** | State changes, events, schedules, service calls, storage and more. |
| **[Deploying](deploy/index.md)** | Running Stewart on a server, from Git, from an image, or on Kubernetes. |
| **[Troubleshooting](help/troubleshooting.md)** | Something does not work? Find it by its symptom. |

Curious how Stewart is built? The [developer documentation](https://stewart-php.github.io/docs-dev/) covers its
internals.
