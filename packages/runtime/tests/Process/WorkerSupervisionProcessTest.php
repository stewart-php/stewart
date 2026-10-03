<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Process;

use Amp\CancelledException;
use Amp\Parallel\Context\ProcessContextFactory;
use Amp\TimeoutCancellation;
use Closure;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\ProcessWorkerSpawner;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Ipc\Message\StateSnapshot;
use Stewart\Runtime\Ipc\Message\WorkerReady;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Broker\RecordingPoolListener;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Tests\Fixtures\Protocol\LingeringWatcher;

use function Amp\delay;

#[CoversNothing]
final class WorkerSupervisionProcessTest extends TestCase
{
    private const float GRACE_SECONDS = 0.5;

    private const float SHUTDOWN_BUDGET_SECONDS = 4;

    private const float SPAWN_DEADLINE_SECONDS = 0.5;

    private const float SPAWN_BUDGET_SECONDS = 2;

    private const float READY_DEADLINE_SECONDS = 10;

    private const string EXITS_WITHOUT_CONNECTING = 'sleep 0.2';

    public function testLingeringWorkerIsKilledOnceGraceRunsOut(): void
    {
        $appId = new AppId('lingering-watcher');
        $listener = new RecordingPoolListener([
            TestBootstrap::createForApps([new WorkerApp(id: $appId, class: LingeringWatcher::class, options: [])]),
            new StateSnapshot(EntityStatesFragment::fromCollection(EntityStateCollection::keyedByEntityId([])), 1),
        ]);
        $pools = WorkerPoolFixture::createWorkerPool(new ProcessWorkerSpawner(new ProcessContextFactory(), IpcCodec::createForWorkerBootstrap()));
        $slot = new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(id: $appId, class: LingeringWatcher::class)]));

        self::assertTrue($pools->pool->startWorker($slot, $listener));
        self::waitUntil(static fn(): bool => array_any($listener->messages, static fn(object $message): bool => $message instanceof WorkerReady));
        $pid = $pools->slots->findHandleForWorker($slot->workerId)?->getPid() ?? 0;

        $startedAt = hrtime(true);
        $pools->pool->shutdown('test', Duration::seconds(self::GRACE_SECONDS));

        self::assertLessThan(self::SHUTDOWN_BUDGET_SECONDS, self::secondsSince($startedAt));
        self::waitUntil(static fn(): bool => !posix_kill($pid, 0));
    }

    public function testChildThatDiesBeforeConnectingEndsTheSpawn(): void
    {
        $spawner = new ProcessWorkerSpawner(new ProcessContextFactory(binary: ['/bin/sh', '-c', self::EXITS_WITHOUT_CONNECTING]), IpcCodec::createForWorkerBootstrap());
        $startedAt = hrtime(true);

        try {
            $spawner->spawn(new WorkerId(0), new TimeoutCancellation(self::SPAWN_DEADLINE_SECONDS));
            self::fail('A child that dies before connecting must not leave the spawn waiting.');
        } catch (CancelledException) {
            self::assertLessThan(self::SPAWN_BUDGET_SECONDS, self::secondsSince($startedAt));
        }
    }

    /** @param Closure(): bool $condition */
    private static function waitUntil(Closure $condition): void
    {
        $startedAt = hrtime(true);

        while (!$condition()) {
            if (self::secondsSince($startedAt) > self::READY_DEADLINE_SECONDS) {
                self::fail('The worker process did not reach the expected state in time.');
            }

            delay(0.05);
        }
    }

    private static function secondsSince(int|float $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1e9;
    }
}
