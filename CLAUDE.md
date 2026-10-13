# Agents instructions


- If it necessary to store these decisions/history use ADRs or other documents, but try to minimize
- Dont commit anything in git by yourself
- Commit messages: `type(component): few-word summary`, an empty line, then a few `- ` bullets naming the main changes, each only a few words; no decisions, reasons or history, nothing else
- PHP runs only in the container; use the `make` targets (`make check`, or `stan`/`cs`/`test` alone). `make test-package PKG=<name>` installs one package on its own; `make test-packages [LOWEST=1]` runs them all.

# Coding instuctions
- Use as little comments in the code as possible
- Limit the length of comment to a very brief summary
- Dont store history, or historical decisions and changes in comments
- Docblocks hold type tags only; a "why" is a short plain `//` comment, no personification or rhetoric. A comment explaining a constant becomes a better constant name (`CommentBarTest` enforces the docblock form).
- Try minimizing the usage of simple arrays to transfer data, use proper objects to do that.
- Use descriptive variable/class/method names if it makes sense
- Use VOs/DTOs where make sense
- You must act like a staff level software engineer / architect
- You have to focus on code quality, structure, maintainability and readability.
- A set of objects travels as a typed collection when it crosses the app boundary (non-private members in `contracts` and `testing`, enforced by `TypedCollectionRule`) or when it carries behaviour (`find()`, domain queries, invariants); elsewhere `list<Obj>`/`array<Id, Obj>` is fine. A collection extends `Stewart\Contracts\Collection\TypedCollection`, is named `<Element>Collection`, and lives in a `Collection` sub-namespace next to its element (`Stewart\Runtime\App\AppDefinition` → `Stewart\Runtime\App\Collection\AppDefinitionCollection`); tests mirror that namespace.
- List collections are built with `fromList()`; add a named factory only when it does work (sorting, deduplication, mapping). Keyed collections expose `keyedByXxxId(iterable)` via `keyedBy()` plus a typed `find(XxxId)`; never `of()`. Keep collections wire-agnostic; JSON goes through `Ipc/Wire/*Fragment` wrappers.
- Static only for VO named constructors and pure formatting/lookups; everything else lives on its owner type or in an injected service.
- Method names are verb phrases that say what happens (`createBroker()`, `seedFromSnapshot()`, `openForWorkerStore()`); never bare `of()`, `in()`, `decode()`, `build()`, `create()`. `with*` names only immutable copies; a method that changes the object says so (`recordDetail()`).
- Runtime services are autowired from `packages/runtime/config/{common,console,broker,worker}.php`; no `new` for services outside the kernels in `Runtime\Kernel`. Values known only at run time reach constructors as synthetic services or named bindings set in the kernel, and a constructor parameter that takes a binding gets a name no other class would use (`$subscriptionQueueLimit`, not `$queueLimit`).
- A new failure is an `XError` case with its `messageTemplate()`, thrown where it happens as `XException::createForReason(XError::Case, $previous, key: $value)`; the named arguments fill the template placeholders (`ExceptionContextRule` checks them). Add a named factory only when it computes text (`createForReasonWithAppendedText()` for a conditional extra sentence) or the same reason is thrown from several files. Messages are one plain sentence; add a hint only when it names a key, variable or command. Tests assert `assertThrowsReason()`, not message text, unless the message logic is conditional.
- A new IPC message is a DTO in `Ipc/Message` with `#[IpcMessage(tag: '…')]` (broker messages also return `getOutboxDelivery()`) plus one `WorkerMessageHandler` or `BrokerMessageHandler` class; correlated replies share one handler instead. A request handler holds its logic unless several handlers share it. No `match (true)` switches on message type. Control frames are the exception: `ControlRequestDispatcher` answers them in one `match` over `RuntimeAdmin`, which both the control socket and the HTTP admin call.
- Tests live in their package: `packages/<pkg>/tests/{Unit,Integration,Process,Persistence,Fixtures}` under `Stewart\<Pkg>\Tests\`. Fakes that more than one package needs go in `stewart-php/testing` (`Stewart\Testing\<Area>`, never Runtime or Codegen); the rest stay in the package's `tests/Fixtures`. Root `tests/` holds only repository-wide guards (`Architecture`) and `Integration/Console`.
- A test may import only what its package's `require` + `require-dev` declare (`PackageManifestTest`). Sibling packages are constrained `self.version` (lockstep releases).
- Tests boot the real container profiles and swap in fakes with `SyntheticServices` (`BrokerKernelFixture`, `WorkerKernel` overrides) rather than wiring object graphs by hand.
- Renaming a method used as a DI factory: also grep for it as a quoted string in `packages/runtime/config/*.php` and `Runtime\Container\*`.
- Code that runs before a kernel exists (`bin/stewart`, `bin/worker.php`) may use a static `create*` factory; the kernel config calls the same factory, so the object graph is built in one place.
- Codegen emitters are tagged services implementing `ModelFileEmitter`/`DomainFileEmitter`; generated member names they own are declared through `ReservesMemberNames`, never listed in `IdentifierAllocator`.
- A class that crosses the IPC or control wire has only public promoted constructor parameters; list properties need `#[ListOf]`; property names are the wire keys, so renaming one changes the wire (the goldens in `packages/runtime/tests/Fixtures/Ipc` catch it).

## Communication style

The reader is a senior software engineer. Optimize for fast scanning, not completeness.

- Lead with the answer or result. No preamble ("Great question", "I'll now...", "Let me...").
- No closing summaries, recaps of what you just did, or offers of further help.
- Don't explain concepts a senior engineer already knows (standard patterns, language basics, common tools).
- Don't restate the request or the plan unless it's ambiguous.
- Prefer short sentences and plain words. Cut filler: "basically", "essentially", "it's worth noting", "in order to".
- Use bullets only for lists of 3+ parallel items. No headers in short replies.
- When reporting work: what changed, where (file:line), and anything I must verify or decide. Nothing else.
- If something failed or is uncertain, say so in one line at the top.
- Ask at most one question, and only when blocked. Otherwise pick the sensible default and state the assumption in one line.
- Code comments: only for non-obvious "why". Never narrate what the code does.

Example of a good end-of-task report:
> Fixed race in `sync/queue.go:142`. Mutex now wraps the flush. Added test `TestConcurrentFlush`.
> Assumption: flush is never called from the handler goroutine. Verify if that changes.