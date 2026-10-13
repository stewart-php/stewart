---
title: 'Conventions'
description: 'How code in the Stewart monorepo is written, and why.'
---

These rules keep a large codebase consistent. Many are checked by PHPStan rules in `tests/Architecture` and by
architecture tests; `make check` reports the rest of the ones that can be checked.

## Comments

- Code explains itself through names. Comments are rare and short.
- A docblock holds type tags only (`@param`, `@return`, `@var`, `@throws`). `CommentBarTest` enforces it.
- A comment says *why*, never *what*, and never history ("used to", "since 0.9"). A comment explaining a constant
  usually means the constant needs a better name.

## Names

- Methods are verb phrases that say what happens: `createBroker()`, `seedFromSnapshot()`, `openForWorkerStore()`.
  Never a bare `of()`, `in()`, `decode()`, `build()` or `create()`. Getters are verbs too: `getState()`,
  `listKeys()`, `countChanges()`.
- `with…()` names only methods that return a changed copy. A method that changes its object says so:
  `recordDetail()`.
- Static methods are for named constructors of value objects and for pure formatting or lookups. Everything else
  lives on its owner or in an injected service.

## Data

- Data travels in objects: value objects and DTOs rather than arrays.
- A set of objects is a typed collection when it crosses the app boundary (public members in `contracts` and
  `testing`, checked by `TypedCollectionRule`) or when it has behaviour. Elsewhere `list<Obj>` is fine.
- A collection extends `Stewart\Contracts\Collection\TypedCollection`, is named `<Element>Collection`, and lives in a
  `Collection` namespace next to its element. Lists are built with `fromList()`; keyed collections offer
  `keyedByXxxId()` and a typed `find()`.

## Errors

- A new failure is a case of an `XError` enum with its `messageTemplate()`, thrown as
  `XException::createForReason(XError::Case, $previous, key: $value)`. The named arguments fill the template's
  placeholders; `ExceptionContextRule` checks them.
- A message is one plain sentence, with a hint only when it names a key, variable or command.
- Tests check the reason with `assertThrowsReason()`, not the message text.
- After adding a reason, run `make docs-reference`; the [error reference](https://stewart-php.github.io/docs/reference/errors/)
  is generated from the enums. Add advice for users in `docs/generator/error-advice.php` when there is something to do.

## Services and wiring

- Runtime services are autowired from `packages/runtime/config/{common,console,broker,worker}.php`. Only the kernels
  in `Runtime\Kernel` use `new` for services.
- A value known only at run time reaches a constructor as a synthetic service or a named binding set in the kernel.
  A parameter that takes a binding has a name no other class would use (`$subscriptionQueueLimit`, not
  `$queueLimit`).
- Code that runs before a kernel exists (`bin/stewart`, `bin/worker.php`) may use a static `create…()` factory, and
  the kernel config calls the same factory.
- When renaming a method used as a DI factory, also search for it as a quoted string in `packages/runtime/config/` and
  `Runtime\Container`.

## Messages between processes

- A new IPC message is a DTO in `Ipc/Message` with `#[IpcMessage(tag: '…')]`, plus one `WorkerMessageHandler` or
  `BrokerMessageHandler`. Broker messages also return `getOutboxDelivery()`. Correlated replies share one handler.
- A class that crosses the IPC or control wire has only public promoted constructor parameters, and list properties
  carry `#[ListOf]`. Property names are the wire keys, so renaming one changes the protocol; the goldens in
  `packages/runtime/tests/Fixtures/Ipc` catch it.
- Control frames are answered in one `match` in `ControlRequestDispatcher`, over `RuntimeAdmin`, which the HTTP admin
  API calls too.

## Codegen

Emitters are tagged services implementing `ModelFileEmitter` or `DomainFileEmitter`. Member names an emitter
generates are declared through `ReservesMemberNames`.

## Packages

- A package may import only what its `composer.json` requires; `PackageManifestTest` checks source and tests.
- Packages require each other as `self.version`: everything is released together.
- `contracts` depends on nothing of Stewart's. Apps import only `contracts` (and `testing` in tests).

## Tests

See [Testing](testing.md).
