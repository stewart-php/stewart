---
title: Handling errors
description: What an automation can catch, how reasons identify a failure, and where the details show up in the logs.
sidebar:
  order: 12
---

Everything Stewart throws is a `Stewart\Contracts\Exception\StewartException`.
One catch sees every error the framework raises:

```php
use Stewart\Contracts\Exception\StewartException;

try {
    $this->ha->callService('light', 'turn_on', target: ServiceTarget::entity('light.hall'));
} catch (StewartException $e) {
    $this->logger->warning('Could not turn the hall light on', ['exception' => $e]);
}
```

## Narrowing by area, then by reason

Each kind of failure has its own class, and the class tells you which part of
Stewart failed. An automation usually meets these ones:

| Class | Thrown by |
|---|---|
| `ServiceCallException` | `callService()` and the generated service methods |
| `HistoryException` | `getHistory()` |
| `EventFireException` | `fireEvent()` |
| `StateException` | `requireState()`, and selectors that cannot match anything |
| `StoreException` | `Store` and `ReadableStore` |
| `ScheduleException`, `TimeException` | the `Scheduler`, `TimeOfDay` and `Duration` |
| `TopicException` | `publish()` with a payload that cannot be carried |
| `WaitException` | `waitForState()` and `waitForNext()`, when the stream completes or the app stops first |

Inside a class, `$e->reason` says which failure it was. Each class has a
reason enum beside it in the same namespace, named after it: `StoreException`
has `StoreError`, `StateException` has `StateError`, and so on:

```php
use Stewart\Contracts\Exception\StoreError;
use Stewart\Contracts\Exception\StoreException;

try {
    $visits = $this->store->increment('visits');
} catch (StoreException $e) {
    if ($e->reason !== StoreError::NotIncrementable) {
        throw $e;
    }

    $this->store->set('visits', 1);
}
```

A failed service call's reason is a `ServiceCallError`: rejected,
unreachable, timed out or overloaded. A failed history query's reason is a
`HistoryError`: the same, plus `RecorderUnavailable`.

Branch on the reason, not on the message. Messages are written for people and
may be reworded. Reasons only change when a failure is split or merged.
`getCode()` is always `0`.

## Context

`$e->context` holds what the error is about, such as the key, the entity or the
service, as a map of scalars:

```php
StoreException::typeMismatch('mode', 'an integer', 'a string')->context;
// ['key' => 'mode', 'expected' => 'an integer', 'actual' => 'a string']
```

## In the logs

Log a `StewartException` under the `exception` key and the daemon adds its
reason and context to the record:

```json
{
  "message": "Could not turn the hall light on",
  "context": {"app": "hall-light", "worker": 0, "exception": "…"},
  "extra": {
    "error": {
      "class": "Stewart\\Contracts\\Exception\\ServiceCallException",
      "reason": "rejected",
      "context": {"domain": "light", "service": "turn_on", "detail": "Entity not found", "errorCode": "not_found"}
    }
  }
}
```

An exception that escapes a handler or `initialize()` is logged as `App failed`
with the same `extra.error`, and `stewart status` shows the reason next to the
app's last failure. The factory that raised it is the one named after the
reason case, `rejected()` for `Rejected`.
