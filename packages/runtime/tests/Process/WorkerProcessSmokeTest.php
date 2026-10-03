<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Process;

use Amp\Parallel\Context\ProcessContextFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\ProcessWorkerSpawner;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Ipc\Message\Ping;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;
use Stewart\Runtime\Ipc\Message\WorkerReady;
use Stewart\Runtime\Ipc\StoreSettings;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Tests\Fixtures\Protocol\EdgeWatcher;
use Stewart\Runtime\Tests\Fixtures\Protocol\RunCounter;
use Stewart\Runtime\Tests\Fixtures\Protocol\WorkerHarness;
use Stewart\Runtime\Time\SystemClock;
use Stewart\Store\Redis\RedisStoreBackendFactory;
use Stewart\Store\StoreDsn;
use Stewart\Store\StoreTiming;
use Stewart\Support\Time\RevoltTimers;

#[CoversNothing]
final class WorkerProcessSmokeTest extends TestCase
{
    private const string WATCHED = 'input_boolean.protocol_test';

    private const string LIGHT = 'light.protocol_test';

    private ?WorkerHarness $worker = null;

    private string $storePrefix;

    protected function setUp(): void
    {
        $this->storePrefix = 'stewart-protocol-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->worker?->close();
        $this->worker = null;

        new RedisStoreBackendFactory(new RevoltTimers())
            ->createBackend(self::getStoreDsn(), new StoreTiming(Duration::seconds(5), Duration::seconds(5)))
            ->removeByPrefix($this->storePrefix . ':');
    }

    public function testRealWorkerProcessBootsServesACallAndStops(): void
    {
        $worker = $this->start([
            new WorkerApp(id: new AppId('edge-watcher'), class: EdgeWatcher::class, options: ['watch' => self::WATCHED, 'light' => self::LIGHT]),
        ]);

        $ready = $worker->receiveUntil(WorkerReady::class);
        self::assertSame(['edge-watcher'], $ready->appIds->collection->toStrings());

        $worker->sendChanges(self::createToggleChange());
        $request = $worker->receiveUntil(ServiceCallRequest::class);
        self::assertSame([self::LIGHT], array_map(strval(...), $request->target->entityIds ?? []));

        $worker->send(new ServiceCallResult($request->correlationId, new ServiceResponse('light', 'turn_on')));
        $completion = $worker->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Service call finished');
        self::assertTrue($completion->context['success'] ?? null);

        $worker->send(new Ping(nonce: 42, sentAt: SystemClock::inUtc()->getNow()));
        self::assertSame(42, $worker->receiveUntil(Pong::class)->nonce);

        $summary = $worker->shutDown('smoke done');
        $this->worker = null;

        self::assertStringContainsString('worker 0 stopped (smoke done', $summary);
    }

    public function testWhatAnAppStoredOutlivesTheProcessThatStoredIt(): void
    {
        $apps = [new WorkerApp(id: new AppId('run-counter'), class: RunCounter::class, options: ['watch' => self::WATCHED])];

        $worker = $this->start($apps);
        $first = $worker->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Run counter starting');
        self::assertSame(1, $first->context['run'] ?? null);

        $worker->receiveUntil(WorkerReady::class);
        $worker->sendChanges(self::createToggleChange());
        $worker->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Change counted');
        $worker->shutDown('restarting');

        $worker = $this->start($apps);
        $second = $worker->receiveUntil(LogRecord::class, static fn(LogRecord $log): bool => $log->message === 'Run counter starting');

        self::assertSame(2, $second->context['run'] ?? null, 'The new process reads what the old one left.');
        self::assertSame(1, $second->context['changes_seen_before'] ?? null);

        $worker->shutDown('done');
        $this->worker = null;
    }

    /** @param list<WorkerApp> $apps */
    private function start(array $apps): WorkerHarness
    {
        $this->worker = WorkerHarness::boot(
            new ProcessWorkerSpawner(new ProcessContextFactory(), IpcCodec::createForWorkerBootstrap()),
            TestBootstrap::createForApps($apps, store: new StoreSettings(self::getStoreDsn()->reveal(), $this->storePrefix, Duration::seconds(5), Duration::seconds(5))),
            EntityStatesFragment::fromCollection(EntityStateCollection::keyedByEntityId([
                new EntityState(new EntityId(self::WATCHED), 'off'),
                new EntityState(new EntityId(self::LIGHT), 'off'),
            ])),
        );

        return $this->worker;
    }

    private static function createToggleChange(): StateChange
    {
        return new StateChange(new EntityId(self::WATCHED), new EntityState(new EntityId(self::WATCHED), 'off'), new EntityState(new EntityId(self::WATCHED), 'on'));
    }

    private static function getStoreDsn(): StoreDsn
    {
        $url = getenv('TEST_STORE_URL');

        return StoreDsn::parse($url === false || $url === '' ? 'redis://valkey:6379/15' : $url);
    }
}
