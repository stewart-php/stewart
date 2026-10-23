---
title: Events
description: React to Home Assistant events such as button presses, fire events of your own, tell your own changes from a person's, and send messages between apps.
sidebar:
  order: 4
---

Not everything in Home Assistant is a state. A remote's button press, an automation that ran, or a script's custom
signal arrive as **events** on Home Assistant's event bus. This app toggles a light when a Zigbee remote sends
`toggle`:

```php file=../../examples/apps/RemoteToggle.php
```

## Watching events

`watchEvents('zha_event')` returns a stream of events of that type. It works like a state stream, with the same
operators (`filter`, `debounce`, `throttle`, `take`, …). The type accepts the same patterns as entities: an exact type,
`*_event`, a list, or `*` for every event.

Each `HaEvent` holds:

| Property | Content |
|---|---|
| `$event->type` | The event type, such as `zha_event` |
| `$event->data` | The event's data as an array; `$event->getValue('command')` reads one key |
| `$event->firedAt` | When Home Assistant fired it |
| `$event->context` | Who caused it: the context ID, its parent, and the user |

The data is a plain array, because only the integration that fires an event knows its shape. To see what an event
carries, open **Developer tools → Events** in Home Assistant, enter the type under **Listen to events**, and press the
button.

`state_changed` is the one type you cannot watch here; `watchEvents('state_changed')` throws. Use
`watchStateChanges()`, which is built for it.

## Firing events

`fireEvent()` puts an event on Home Assistant's bus, where automations, scripts and Node-RED can react to it:

```php file=../../examples/apps/DoorbellAnnouncer.php
```

- The data must be an array keyed by name, holding only strings, numbers, booleans, `null`, and arrays of those.
  Anything else throws `EventFireException` before the event is sent.
- A fired event counts as a call to Home Assistant: it shares the limits of action calls, and with dry run on, it
  is logged but not sent. See [Call actions](calling-actions.md).
- Home Assistant lets only administrators fire events.

## Your own changes, or someone else's

An automation often needs to know whether a change came from itself or from a person. The classic case is a manual
override: someone switches the hall light by hand, so the motion automation should leave it alone for a while.

```php file=../../examples/apps/ManualOverride.php
```

`$this->ha->wasCausedByStewart($change)` is `true` when Stewart made the change, from any app. It compares the user
behind the change with the user of Stewart's token, so it needs Stewart to have [its own Home Assistant
user](../start/install.md#1-create-a-home-assistant-user-for-stewart). With a token from your own account,
everything you do counts as Stewart's.

Every `callAction()` returns an `EventContext`, which identifies that call. `$change->wasCausedBy($context)` is `true`
for changes made by that exact call, including through a script or scene it started. Home Assistant often reports
the first change before `callAction()` returns, so you may not have the context yet. To ask "did a person do
this?", use `wasCausedByStewart()` instead.

| Method | Answers |
|---|---|
| `$ha->wasCausedByStewart($change)` | Did any Stewart app cause this? |
| `$change->wasCausedBy($context)` | Did this call cause it? |
| `$state->wasLastChangedBy($context)` | Did this call cause the entity's last change? |

A script that Stewart starts runs as the script, not as Stewart's user, so its changes are not "caused by Stewart".
`wasCausedBy($context)` still matches them.

## Messages between apps

Apps can send each other messages without Home Assistant. `publish()` sends a payload on a topic, and
`watchTopic()` receives it in any app, on any worker:

```php
$this->ha->publish('alarm/armed', ['by' => 'night-lock']);

$this->ha->watchTopic('alarm/*')->subscribe(function (TopicEvent $message): void {
    $this->logger->info("{$message->topic} from {$message->publisher?->value}");
});
```

The payload may hold strings, numbers, booleans, `null` and arrays of those. Messages exist only inside Stewart;
Home Assistant does not see them. To reach Home Assistant, use `fireEvent()`.

## Testing

```php file=../../examples/tests/ManualOverrideTest.php
```

`pushState()` takes the context a change carries. A context with a `userId` other than Stewart's counts as a person.
`pushEvent()` sends an event, and `$harness->ha->firedEvents` lists the events the app fired.

## Common problems

**`AdministratorRequired` at start.** Stewart watches the whole event bus, which Home Assistant allows only for
administrators. Create the token from an administrator user.

**Every change counts as Stewart's.** The token belongs to your own user. Give Stewart its own user.

**Events are dropped, with a warning naming the worker.** A worker that falls far behind drops its oldest queued
events. Look for a handler that blocks, such as one calling `sleep()`.
