---
title: Handling errors
description: What happens when an app throws, which exceptions Stewart raises, and how to tell them apart by reason.
sidebar:
  order: 19
---

When something fails, Stewart throws an exception. You can let it go, or catch it where your app can do something
better. This app counts doorbell rings, and starts again when the stored count is not a number:

```php file=../../examples/apps/VisitCounter.php
```

## When you do not catch it

- **In a handler**, Stewart logs `App failed` with the exception, counts the failure in `make status`, and the app
  keeps running. The next event runs the handler again.
- **In `initialize()`**, the app does not start. Other apps keep running.

So catch an exception only when the app can do something useful, such as trying another way, sending a notification,
or skipping one item.

## Which exception, and why

Every exception Stewart throws extends `Stewart\Contracts\Exception\StewartException`. Each part of Stewart has its
own class:

| Class | Thrown by |
|---|---|
| `ServiceCallException` | `callService()`, `callServiceForResponse()` and the generated service methods |
| `HistoryException` | `getHistory()` |
| `EventFireException` | `fireEvent()` |
| `StateException` | `requireState()` for an entity that does not exist, and invalid operator settings |
| `StoreException` | `Store` and `ReadableStore` |
| `ScheduleException`, `TimeException` | The `Scheduler`, and invalid times or durations |
| `WaitException` | `waitForState()` and `waitForNext()`, when the stream ends or the app stops first |
| `ExposureException`, `CommandException` | [Exposed entities](exposed-entities.md) |
| `RegistryEditException` | `RegistryEditor` |
| `IdentifierException` | An invalid entity ID, or one the [typed classes](codegen.md) do not know |
| `TopicException`, `MqttException`, `TriggerException`, `SunException` | Messages between apps, MQTT, triggers, the sun |

`$e->reason` says what exactly failed. It is an enum named after the class: `StoreException` has `StoreError`,
`ServiceCallException` has `ServiceCallError`, and so on. Compare the reason, as the example does, not the message:
messages are written for people and may change, reasons do not. The [error reference](../reference/errors.md) lists
every reason.

`$e->context` holds what the error is about as an array, for example `['domain' => 'light', 'service' =>
'turn_on', 'detail' => '…']`.

## In the logs

Log the exception under the `exception` key, as the example does. Stewart then adds its class, reason and context to
the log line. With `log_format: json`, that is the `extra.error` field:

```json
{
  "message": "App failed",
  "context": {"app": "hall-light", "exception": "…"},
  "extra": {
    "error": {
      "class": "Stewart\\Contracts\\Exception\\ServiceCallException",
      "reason": "rejected",
      "context": {"domain": "light", "service": "turn_on", "detail": "Entity not found"}
    }
  }
}
```

`make status` shows each app's last failure with its reason.

## Testing

A test sees an exception from a handler directly: the harness throws it out of the `pushState()`, `pushEvent()` or
`advanceTime()` that ran the handler. To test a failing service, use `stubServiceFailure()`; see
[Calling services](calling-services.md#testing).

```php file=../../examples/tests/VisitCounterTest.php
```
