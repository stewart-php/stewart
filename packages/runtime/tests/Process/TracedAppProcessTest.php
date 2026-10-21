<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Process;

use Amp\Parallel\Context\ProcessContextFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Runtime\Broker\ProcessWorkerSpawner;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Ipc\Message\WorkerReady;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Model\AppVerbosity;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Tests\Fixtures\Protocol\SwitchOnWatcher;
use Stewart\Runtime\Tests\Fixtures\Protocol\WorkerHarness;
use Stewart\Support\State\StateUpdate;

#[CoversNothing]
final class TracedAppProcessTest extends TestCase
{
    private const string WATCHED = 'input_boolean.trace_test';

    private ?WorkerHarness $worker = null;

    protected function tearDown(): void
    {
        $this->worker?->close();
        $this->worker = null;
    }

    public function testTraceShowsWhichChangeTheFilterStopped(): void
    {
        $worker = $this->start(new WorkerApp(
            id: new AppId('switch-on-watcher'),
            class: SwitchOnWatcher::class,
            options: ['watch' => self::WATCHED],
            verbosity: new AppVerbosity(LogLevel::Info, trace: true),
        ));
        $worker->receiveUntil(WorkerReady::class);

        $worker->sendUpdates(self::createUpdate('off'));
        $worker->sendUpdates(self::createUpdate('on'));
        $traces = [];
        $worker->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Switched on', static function (object $message) use (&$traces): void {
            if ($message instanceof LogRecord && $message->trace) {
                $traces[] = [$message->message, $message->context['change'] ?? null];
            }
        });

        self::assertSame([['Event received', 'on → off'], ['Event received', 'off → on'], ['Handler called', null]], $traces, 'The off change was received, then stopped by the filter.');

        $worker->shutDown('traced');
        $this->worker = null;
    }

    private function start(WorkerApp $app): WorkerHarness
    {
        $this->worker = WorkerHarness::boot(
            new ProcessWorkerSpawner(new ProcessContextFactory(), IpcCodec::createForWorkerBootstrap()),
            TestBootstrap::createForApps([$app], logLevel: LogLevel::Warning),
            EntityStatesFragment::fromCollection(EntityStateCollection::keyedByEntityId([new EntityState(new EntityId(self::WATCHED), 'on')])),
        );

        return $this->worker;
    }

    private static function createUpdate(string $state): StateUpdate
    {
        return new StateUpdate(new EntityId(self::WATCHED), new EntityState(new EntityId(self::WATCHED), $state));
    }
}
