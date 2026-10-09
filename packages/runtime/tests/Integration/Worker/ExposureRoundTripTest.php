<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Worker;

use Amp\Future;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\Exposure\ExposureProxy;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Broker\BrokerKernelFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Protocol\TankLevelExposer;
use Stewart\Runtime\Tests\Fixtures\Worker\InMemoryWorkerSpawner;
use Stewart\Runtime\Worker\WorkerEntityExposure;
use Stewart\Testing\Logging\RecordingLogger;

use function Amp\async;

#[CoversClass(ExposureProxy::class)]
#[CoversClass(WorkerEntityExposure::class)]
final class ExposureRoundTripTest extends TestCase
{
    private const float WAIT_SECONDS = 5;

    public function testAppValueReachesTheSession(): void
    {
        $session = new FakeHaSession();
        $logger = new RecordingLogger();
        $pools = WorkerPoolFixture::createWorkerPool(new InMemoryWorkerSpawner(IpcCodec::createForWorkerBootstrap()), logger: $logger);
        $broker = BrokerKernelFixture::boot(
            $session,
            $pools,
            WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([
                new AppDefinition(new AppId('tank-level'), TankLevelExposer::class),
            ]))]),
            AppIdCollection::fromIds([new AppId('tank-level')]),
            $logger,
            ['shutdown_grace' => '1s'],
        );
        $updated = $session->waitForNextExposedUpdate();

        /** @var Future<null> $running */
        $running = async($broker->lifecycle->run(...));

        self::assertSame(TankLevelExposer::LEVEL, $updated->await(new TimeoutCancellation(self::WAIT_SECONDS))->state?->value);

        $broker->run->stop('test done');
        $running->await(new TimeoutCancellation(self::WAIT_SECONDS));
    }
}
