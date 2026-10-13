---
title: Writing apps
description: Configuration, the app API, streams, events, schedules and waits.
sidebar:
  order: 1
---

## Configuration

You can configure Stewart three ways

### Using the configuration file

The configuration file lets you configure every Stewart setting. To generate a reference configuration file
run the `make config-reference` to dump out the full configuration with reference (default) values.

### Using environment variables

Every setting in the reference configuration can be set from the environment variables instead:
Always start with the `STEWART_` prefix, then the config keys, uppercased, joined by a **double** underscore.
A single underscore is part of a key name.

| in configuration | in environment variable |
|---|---|
| `log_level` | `STEWART_LOG_LEVEL` |
| `worker_event_buffer` | `STEWART_WORKER_EVENT_BUFFER` |
| `apps.demo.worker` | `STEWART_APPS__DEMO__WORKER` |
| `apps.demo.options.watch` | `STEWART_APPS__DEMO__OPTIONS__WATCH` |

Durations are one number with one unit — `500ms`, `5s`, `2m`, `1h` — in the file
and in the environment alike. A bare number is refused, because its unit would
be a guess.

A setting you leave out keeps its default; `make config-reference` lists them
all. To switch something off, set it to the word `off`: the timers
`supervision.ping_interval`, `supervision.initialize_timeout`,
`supervision.ready_timeout` and `home_assistant.heartbeat_interval`, and the
control socket's `control.listen`.

```yaml
supervision:
  ping_interval: off
```

In the environment that is `STEWART_SUPERVISION__PING_INTERVAL=off`. An empty
variable means unset, not off, so the default applies.

:::note[Lists and maps in environment variables]
An app option or a list setting takes inline YAML, and the value replaces the
whole option or list:

`STEWART_APPS__DEMO__OPTIONS__WATCH='[light.hall, light.porch]'`
`STEWART_APPS__DEMO__OPTIONS__LIMITS='{min: 5, max: 20}'`
`STEWART_CODEGEN__INCLUDE='[light.*, switch.*]'`

A variable cannot reach below an option (`…__OPTIONS__LIMITS__MIN`); set the
option whole. An app whose ID has a `-` is found through its entry in the file,
so give it one, even if empty: `hall-light: ~`.
:::

### Reading environment variables in the file

A string value in `stewart.yaml` can read a variable, whole or inside a longer
value, with a fallback after `:-`:

```yaml
home_assistant:
  url: ws://${HA_HOST}:8123
  token: ${HA_TOKEN}
log_level: ${LOG_LEVEL:-info}
```

A variable that is not set and has no fallback stops Stewart with the setting's
name. Write `$${` for a literal `${`. `STEWART_*` variables still win over what
the file says.

### Using command line arguments

As an argument there is only three options are available, command line options wins both over
configuration files and environment variables.

| Argument | description |
|---|---|
| `--config (-c)` | Sets where the config file is read from (default: `./stewart.yaml`) |
| `--log-level (-l)` | Define the log level |
| `--json-logs` | If set to `true` application will emit logs in JSON format |

```bash
make config-reference  # Dump reference config
make config            # what is in effect, and from where
```

## Writing an app

Apps live in `apps/`, implement `App`, and carry `#[Automation]`. That is all
it takes: the attribute is how Stewart finds them, so there is no list to keep
in step.

The ID is explicit rather than taken from the class name, because it keys the
app's logs and its stored state — rename the class and it is still the same
automation.

It starts with a lowercase letter and holds only lowercase letters, digits, `-`
and `_`. The ID has to survive three places at once: a log field, a key in
`stewart.yaml`, and a `STEWART_APPS__*` environment variable. Because that last
one cannot tell `-` from `_`, `hall-light` and `hall_light` are the same name
and cannot both exist.

A constructor takes whatever it needs, by type, in any order:

```php
#[Automation(id: 'hall-light')]
final class HallLight implements App
{
    public function __construct(
        private readonly HaContext $ha,
        private readonly Forecast $forecast,    // from services.php
        private readonly int $brightness = 180, // from options: below
    ) {}
}
```

`stewart.yaml` is then optional, and only for what belongs to a deployment
rather than to the code:

```yaml
apps:
  hall-light:          # must match the ID in the attribute
    worker: 0          # optional: pin to a worker
    enabled: true      # optional: switch it off without deleting it
    paused: false      # optional: load it, but deliver nothing until resumed
    options:
      brightness: 200  # passed as named constructor arguments
```

An entry naming an ID no automation declares is an error rather than a shrug,
and so is an option matching no constructor parameter. The second one fails every
automation in that worker, with a message that names the class.

To inject your own classes, register them in `services.php` at the project root;
they are then autowired into any automation that asks for them. The file is
optional — without it you still get the context and the options. It is loaded in
each worker process, so define services there rather than doing work there, and
never an automation: those are configured in `stewart.yaml`.

```php
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    $services->set(App\Weather\Forecast::class);
    // or a whole directory at once
    $services->load('App\\', __DIR__ . '/src/');
};
```

A `Store` parameter in one of these classes is the shared scope; see
[Persistence](persistence.md) for the attributes that pick another.

The `HaContext` gives you:

| | |
|---|---|
| `getState($id)` / `requireState($id)` | current state — **never suspends**, it is a local hash lookup |
| `listStates($selector)` | every matching entity, as an `EntityStateCollection` |
| `watchStateChanges($selector)` | a stream of changes; operators, then `->subscribe(...)` to listen |
| `waitForState($id, $state, timeout:)` | suspends until the entity is in `$state` and returns its `EntityState`, at once if it already is; null once the timeout passes. See [Waiting](#waiting) |
| `getRegistry()` | Home Assistant's areas, floors, labels and devices — **never suspends**; see [Areas, floors and labels](registry.md) |
| `watchEvents($eventType)` | a stream of everything else on Home Assistant's bus |
| `watchTrigger($trigger)` | a stream of Home Assistant triggers: sun, time, template, zone and any other platform; see [Triggers](triggers.md) |
| `callService(...)` / `callServiceForResponse(...)` | calls Home Assistant and awaits it; the first returns the call's `EventContext`, the second the `ServiceResponse` (with `->context`). Null fields are left out |
| `fireEvent($type, $data)` | fires an event on Home Assistant's bus and awaits it; returns the event's `EventContext`. See [Events](#events) |
| `publish($topic, $payload)` / `watchTopic($pattern)` | messaging between apps |
| `watchConnection()` / `isConnected()` | a stream of `ConnectionLost` and `ConnectionRestored`, and the current answer; changes missed during an outage arrive afterwards as reconstructed state changes (`$change->isReconstructed()`) |

Everything else is injected by type:

| | |
|---|---|
| `Psr\Log\LoggerInterface` | PSR-3, already tagged with your app's ID |
| `Stewart\Contracts\Schedule\Scheduler` | schedules, torn down with your app |
| `Stewart\Contracts\Store\Store` | persistence scoped to your app |
| `Stewart\Contracts\Time\Clock` | the current `Instant`, a monotonic reading, Home Assistant's timezone and `isWithin('22:00', '06:00')`: whether local time is in a daily window, start included, end excluded, crossing midnight when the end is earlier |
| `Stewart\Contracts\Sun\SunCalendar` | sunrise, sunset, twilights, golden and blue hour and the sun's position at Home Assistant's location |
| `Stewart\Contracts\Time\Timers` | raw elapsed-time timers, and `waitFor()`, which the waits below are built on; to wait, use `waitForState()` or `waitForNext()` rather than `Amp\delay()`, which tests cannot speed up |

The process runs in Home Assistant's timezone, so `Instant::toDateTime()` and
`date_default_timezone_get()` already agree with it.

An `EntityState` knows how long it has held its value: `$state->hasHeldFor(Duration::minutes(10), $this->clock)`
is true once `lastChangedAt` is at least that far back, and `getHeldDuration($this->clock)` returns the span itself
(null when Home Assistant reported no change time). In tests, `RecordingHaContext::seedState()` takes a `changedAt:`
instant, and `pushState()` moves `lastChangedAt` only when the value differs.

Selectors accept an exact ID (`light.hall`), a glob (`sensor.*_battery`), or a
list. A regex is only ever asked for: `Selector::regex('#^light\.#')`, so an ID
that happens to look like one stays an ID. Patterns pick up entities that
appear later. `listStates()` and `watchStateChanges()` also take an
`EntityFilter`, which picks by area, floor, label or device:
`EntityFilter::inArea('kitchen')->withDomain('light')`.

### Operators

The selector decides which events reach your handler. Everything after it is
ordinary PHP running in your worker on events that already arrived.

```php
$this->ha->watchStateChanges('binary_sensor.hall_motion')
    ->whenChangedTo('on', for: Duration::seconds(3), from: 'off')
    ->subscribe(fn(StateChange $e) => $this->ha->callService('light', 'turn_on'));
```

That is Home Assistant's state trigger, `from:` and `for:` included:

```yaml
trigger:
  platform: state
  from: 'off'
  to: 'on'
  for: '00:00:03'
```

`whenChangedTo()` ignores an arrival whose previous state is `unknown`,
`unavailable` or missing, so a Home Assistant restart or a device rejoining the
network does not fire it. `from: ['off', 'idle']` accepts only those previous
states, and `fromAnyState: true` accepts every one; pass one or the other. They
shape the transition itself, so the `for:` window still sees the entity leave.

| | |
|---|---|
| `filter($predicate)` | keep the events you want |
| `map($fn)` | turn each event into something else |
| `debounce($window, $edge)` | wait for the stream to settle — `Edge::Trailing` by default; per entity on state streams |
| `throttle($window, $edge)` | rate-limit — `Edge::Leading` by default, so it acts at once and then gates; per entity on state streams |
| `distinctUntilChanged()` | state streams only: drop changes that keep an entity's state value |
| `acrossEntities()` | state streams only: a plain stream, so `debounce`/`throttle` act on all entities together |
| `take($n)` / `takeUntil($stream)` | stop after `n` events, or when another stream fires |
| `whenChangedTo($state, for:, from:, fromAnyState:)` | arrivals at that state; with `for`, only once it has held; `from` limits the previous state, `fromAnyState` also accepts `unknown`, `unavailable` or nothing |
| `whenStableFor($window)` | any state, once the entity has settled on it |
| `startWithCurrentState()` | state streams only: first the current state of every matching entity (`$change->isInitial()`), then live changes |
| `whenAbove($threshold, for:, attribute:, hysteresis:)` | numeric state or attribute crossing above the threshold, once per crossing |
| `whenBelow($threshold, for:, attribute:, hysteresis:)` | the same, crossing below |

`startWithCurrentState()` emits each seed where it sits in the chain, so only the operators after it see it; put
it right after `watchStateChanges()` to have filters and `debounce()` apply. A seed's `from` and `to` are the same
state, so `whenChangedTo()` and `whenAbove()` treat it as no change: an entity that is already `on` does not fire
`whenChangedTo('on')`.

`whenAbove(25.0)` fires when the value moves from 25 or less to above 25, then stays quiet until the value
returns. `hysteresis: 0.5` makes it return only at 24.5 or less, so a sensor hovering around the threshold fires
once. A crossing counts only from a known number: a first reading, or one after `unavailable`, primes the operator
without firing. With `for:`, the value must stay above the threshold for the whole window.

Windows are `Duration` values: `Duration::milliseconds(300)`,
`Duration::seconds(2.5)`, `Duration::minutes(5)`, `Duration::hours(1)`.

Both windows ignore an update that does not move the state, so the attributes
Home Assistant republishes constantly cannot starve a long hold.
`distinctUntilChanged()` drops those same republishes.

**Put a window before any filter, not after.** A filter throws away the changes
a window needs in order to know the entity left early:

```php
// Wrong: whenChangedTo() removes the "off", so this fires three seconds
// after motion starts even if motion stopped after one.
->whenChangedTo('on')->whenStableFor(Duration::seconds(3))

// Right: one operator, and it still sees the departure.
->whenChangedTo('on', for: Duration::seconds(3))
```

`watchStateChanges()` gives you a `StateChangeStream`, and every operator that keeps
the element type hands one back — so `whenChangedTo()` still chains after a
`filter()`. On a state stream every timed or stateful operator works per entity:
`watchStateChanges('light.*')->debounce(...)` debounces each light on its own. `map()`
drops out to a plain `EventStream`, since it changes what the stream carries, and
`acrossEntities()` does the same on purpose when you want one window for all.

### Events

Not everything Home Assistant does is a state. A Zigbee button press, an
automation firing, a script emitting something of its own — those arrive on the
event bus, and `watchEvents()` is the stream for them:

```php
$this->ha->watchEvents('zha_event')
    ->filter(fn(HaEvent $e) => $e->getValue('device_ieee') === self::REMOTE)
    ->filter(fn(HaEvent $e) => $e->getValue('command') === 'toggle')
    ->subscribe(fn() => $this->ha->callService('light', 'toggle', target: ServiceTarget::forEntities('light.hall')));
```

An `HaEvent` carries the type, the payload, and where it came from:

| | |
|---|---|
| `$event->type` | `zha_event`, `automation_triggered`, your own name |
| `$event->data` / `$event->getValue($key)` | the event's payload, as Home Assistant sent it |
| `$event->origin` | `EventOrigin::Local` or `EventOrigin::Remote` |
| `$event->firedAt` | when Home Assistant fired it, as an `Instant` |
| `$event->context` | the id, parent and user behind it |

The payload is an array rather than a typed object, because only the integration
that fires an event knows its shape. Read it with `value()` and narrow it with
`filter()`, exactly like any other stream — every operator works here too.

Event types take the same selectors as entities: `zha_event`, `*_event`,
`#^hue_#`, a list of any of those, or `*` for the whole bus.

**`state_changed` is not on this stream.** It is the one event `watchEvents()`
refuses, with an exception pointing at `watchStateChanges()` — state changes are the
bulk of the traffic and get their own path, with per-entity coalescing that this
stream cannot offer. A pattern like `state_*` is accepted and simply never sees
it.

To try it without any hardware, fire one by hand: **Developer Tools → Events →
Fire event**, with `stewart_demo` as the type and any JSON you like as the data.
The shipped demo app logs whatever arrives.

Apps fire events of their own with `fireEvent()`. The event goes onto Home
Assistant's bus, where automations, Node-RED and other Stewart apps see it.
`publish()` is different: it reaches only Stewart apps.

```php
public function initialize(): void
{
    $this->ha->watchEvents('doorbell_pressed')
        ->filter(fn(HaEvent $e) => !$this->ha->wasCausedByStewart($e))
        ->subscribe(fn(HaEvent $e) => $this->logger->info('Someone else rang', $e->data));
}

public function ring(): void
{
    $this->ha->fireEvent('doorbell_pressed', ['button' => 'front', 'note' => null]);
}
```

Home Assistant sends the fired event to every app watching its type, including
the one that fired it, and usually before `fireEvent()` returns. A watcher
therefore cannot wait for the returned context. `wasCausedByStewart($event)`
reads the event's user, which is set from the start. The returned `EventContext` still identifies this exact event,
for matching what it starts later.

The data must be keyed by name and hold only null, finite scalars and arrays of
those. Nulls are sent as they are. Anything else throws `EventFireException`
before the event leaves the worker. A fired event counts as a call to Home
Assistant: it shares the `service_calls.max_in_flight*` limits, shows up in the
per-app call counts, and under `service_calls.dry_run` it is logged but not sent.

:::caution[The token must belong to an administrator]
Stewart subscribes to the whole event bus in one go, which Home Assistant only
allows for admin users. With a non-admin token the daemon stops at boot with
`HaClientError::AdministratorRequired` rather than running on with a filtered view of the
world. Issue the long-lived token from an administrator account, ideally one created for Stewart alone (see [Your own changes](#your-own-changes)).
:::

Delivery is the same drop-oldest deal as everywhere else: a worker that falls
behind loses its oldest **queued** events — never silently, always with a warning
naming the worker and the count. Unlike state changes, events cannot be
coalesced: two presses of the same button are two facts, not one. And an event
that arrives while Stewart is rebuilding its state after losing Home Assistant is
dropped, because a handler reacting to it would be reading state from before the
outage.

### Your own changes

Every service call returns the `EventContext` Home Assistant gave it, and every
state change it causes carries that context. That is how an app tells its own
changes from someone flipping a switch by hand — the classic manual override:

```php
use Stewart\Contracts\State\StateChange;

public function __construct(private HaContext $ha) {}

public function initialize(): void
{
    $this->ha->watchStateChanges('light.hall')
        ->filter(fn(StateChange $change) => !$this->ha->wasCausedByStewart($change))
        ->subscribe(fn() => $this->pauseAutomationFor(Duration::hours(1)));
}
```

| | |
|---|---|
| `$ha->wasCausedByStewart($change)` | Stewart made it: any app, any call. Read from the change's user, so it works from the very first change |
| `$change->wasCausedBy($context)` | this exact call made it, or a script or scene the call started |
| `$state->wasLastChangedBy($context)` | the same question about an `EntityState`'s last change |

Home Assistant sends the first state change of a call *before* it answers the
call, so a handler often sees that change while `callService()` has not
returned yet. `wasCausedBy()` cannot match it then; `wasCausedByStewart()` can.
Use the context for later questions ("is the light still the way I left
it?"), and `wasCausedByStewart()` for "did a person do this?".

:::caution[Give Stewart its own Home Assistant user]
`wasCausedByStewart()` compares the change's user with the user the access token
belongs to. Create a separate administrator user for Stewart and issue the token
from it. With a token from your own account, everything you change in the Home
Assistant UI or app counts as Stewart's.
:::

Changes made by a script or automation that Stewart started belong to that
script, not to Stewart's user, so `wasCausedByStewart()` is false for them;
`wasCausedBy($context)` still matches them. With `service_calls.dry_run` on,
the context id starts with `dry-run:` and matches nothing.

In tests, `RecordingHaContext` gives every call a context for
`RecordingHaContext::STEWART_USER_ID`, answers `wasCausedByStewart()` for that
user, and `pushState()` takes the context a change should carry:

```php
$ha = new RecordingHaContext();
$app = new HallLight($ha);
$app->initialize();

$ha->pushState('light.hall', 'off', context: new EventContext('manual', userId: 'resident'));
```

### Schedules

Time-based work comes from a `Scheduler`, injected beside the context:

```php
public function __construct(
    private readonly HaContext $ha,
    private readonly Scheduler $scheduler,
) {}

public function initialize(): void
{
    $this->scheduler->runDailyAt('07:00', $this->wake(...), DayOfWeek::Monday, DayOfWeek::Friday);
    $this->scheduler->runEvery(Duration::minutes(5), $this->poll(...));
    $this->scheduler->runOnCron('0 22 * * *', $this->goodnight(...));
    $this->scheduler->runAfter(Duration::minutes(20), $this->dim(...));
    $this->scheduler->runAtSunEvent(SunEvent::Sunset, $this->lightsOn(...), SunOffset::before(Duration::minutes(30)));
}
```

| | |
|---|---|
| `runEvery($period, $handler)` | repeat, starting one period after your app goes live |
| `runDailyAt($time, $handler, ...$days)` | a wall-clock time, every day or on named `DayOfWeek`s |
| `runOnCron($expression, $handler)` | a standard five-field expression |
| `runAfter($delay, $handler)` / `runAt($moment, $handler)` | once |
| `runAtSunEvent($event, $handler, $offset)` | every day at a `SunEvent` (dawn, golden hour, dusk...), optionally `SunOffset::before()`/`after()` it |
| `runAtEntityTime($entity, $handler)` | at the time an `input_datetime` or a timestamp `sensor` holds, re-armed whenever it changes |

Times are `'07:00'`, `'07:00:30'` or a `TimeOfDay`; moments passed to `runAt()` are
any `DateTimeImmutable`, so Carbon works unchanged. Every handler is given a
`ScheduledRun`: its `ScheduledTask`, the occurrence it is serving
(`scheduledFor`), when it actually started (`firedAt`), how late that was
(`getLateness()`), and how many occurrences it overran (`missedOccurrences`). A
handler that declares no parameter is fine.

Registration hands back a `ScheduledTask` you can `cancel()` (also reachable as
`$run->task` inside the handler), and schedules are torn down with your app, so
there is nothing to clean up in `dispose()`. A schedule that can never occur, such
as `runAt()` a moment already past, is logged as a warning and handed back inactive.

`runAtEntityTime('input_datetime.wake_up', $handler)` reads the entity in Home Assistant's timezone: a date and time
runs once, a time alone runs every day, and a timestamp sensor runs at its instant. While the entity holds no future
time (date only, a moment already past, `unavailable`), the task stays active with no `getNextRunAt()` and arms on
the entity's next change, without a warning. Resuming a paused app reads the entity again, and so does every run
right before it starts, so a change made while the app was paused is never lost. Runs, logs and metrics carry the
task's own `getId()` across every re-arm.

**Times are Home Assistant's, not the container's.** A 07:00 schedule agrees with
Home Assistant's idea of 07:00. It follows that the two days a year the clocks
move are decided rather than accidental: a time the clocks jumped over does not
happen that day, and a time that happens twice happens once, at the first. For a
sub-hourly cron such as `*/15 * * * *` that means a pause through the repeated
hour in autumn; use `runEvery()` for work that must not pause.

**`runEvery()` and `runAfter()` count elapsed time; the rest follow the wall clock.** An
NTP correction or a clock that steps back does not stall a five-minute poll, and
does not fire a 20-minute timer early. Elapsed time does not advance while the
host is suspended.

**A run that is missed is not replayed, and one that overruns is not doubled.**
If the process was down at 03:00, the 03:00 job does not run at 09:00 — the next
occurrence is the next one due. If a handler is still going when its next
occurrence arrives, that occurrence is dropped rather than queued, so two copies
of the same automation never run at once.

Nothing of your app's runs before its `initialize()` returns. Scheduling from a
constructor works — the schedule waits — but by `initialize()` the state cache
is seeded, so it is the natural place. A wall-clock occurrence that fell due
while the app was starting runs once, late, when it goes live.

### Waiting

Some automations are a sequence: lock the door, then check that it locked. `waitForState()` suspends the handler
until the entity reaches a state, and `waitForNext()` until a stream's next event:

```php
$this->ha->callService('lock', 'lock', target: ServiceTarget::forEntities('lock.front_door'));

if ($this->ha->waitForState('lock.front_door', 'locked', Duration::seconds(10)) === null) {
    $this->ha->callService('notify', 'notify', ['message' => 'The front door did not lock']);
}

$press = $this->ha->watchEvents('zha_event')
    ->filter(fn(HaEvent $e) => $e->getValue('device_ieee') === self::REMOTE)
    ->waitForNext(Duration::minutes(1));
```

| | |
|---|---|
| `$ha->waitForState($id, $state, $timeout)` | the entity's `EntityState` once it is in `$state`; at once if it already is |
| `$entity->waitForState($state, $timeout)` | the same on an `Entity`; a generated handle returns its typed state |
| `$stream->waitForNext($timeout)` | the next event the stream would hand a subscriber, after its operators |

- **A timeout returns null.** Without one, the wait lasts until it is satisfied or the app stops.
- **The wait ends with `WaitException` when its stream completes first**, for example a `take()` or `takeUntil()`
  that has finished. When the app stops, pending waits end the same way; that is part of stopping and is not
  reported as a failure.
- **A wait inside a handler holds back that subscription's later events.** Each subscription handles its events one
  at a time, so the next change of the same subscription arrives after the wait ends. Other subscriptions and
  schedules are not affected.
- **Do not wait in `initialize()`.** Events reach the app only after `initialize()` returns, so a wait there ends
  only by its timeout, unless the entity is already in the state. Subscribe or schedule from `initialize()`, and
  wait in the handler.
- **Pausing does not stop the clock.** A wait's timeout keeps running while the app is paused, and states that arrive
  meanwhile are held back like any other event, so a paused wait usually ends at its timeout.
- An awaited command from `watchCommands()` is accepted as soon as it is returned, like one whose handler returns.

`whenChangedTo('off', for: ...)` remains the tool for "has stayed off for five minutes"; waits are for steps that
follow one another.
