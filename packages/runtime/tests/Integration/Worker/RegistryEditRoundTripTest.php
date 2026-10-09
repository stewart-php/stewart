<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Worker;

use Amp\Future;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\RegistryEditProxy;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Broker\BrokerKernelFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Protocol\HallLampRenamer;
use Stewart\Runtime\Tests\Fixtures\Worker\InMemoryWorkerSpawner;
use Stewart\Runtime\Worker\WorkerRegistryEditor;
use Stewart\Testing\Logging\RecordingLogger;

use function Amp\async;

#[CoversClass(RegistryEditProxy::class)]
#[CoversClass(WorkerRegistryEditor::class)]
final class RegistryEditRoundTripTest extends TestCase
{
    private const float WAIT_SECONDS = 5;

    public function testAppEditReachesTheSession(): void
    {
        $session = new FakeHaSession();
        $session->seedRegistryEntry(new RegisteredEntity(new EntityId(HallLampRenamer::ENTITY_ID)));
        $logger = new RecordingLogger();
        $pools = WorkerPoolFixture::createWorkerPool(new InMemoryWorkerSpawner(IpcCodec::createForWorkerBootstrap()), logger: $logger);
        $broker = BrokerKernelFixture::boot(
            $session,
            $pools,
            WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([
                new AppDefinition(new AppId('hall-lamp-renamer'), HallLampRenamer::class),
            ]))]),
            AppIdCollection::fromIds([new AppId('hall-lamp-renamer')]),
            $logger,
            ['shutdown_grace' => '1s'],
        );
        $updated = $session->waitForNextRegistryUpdate();

        /** @var Future<null> $running */
        $running = async($broker->lifecycle->run(...));

        $entity = $updated->await(new TimeoutCancellation(self::WAIT_SECONDS));
        self::assertSame(HallLampRenamer::NAME, $entity->name);
        self::assertSame(['night'], $entity->listLabelIds()->toStrings());

        $broker->run->stop('test done');
        $running->await(new TimeoutCancellation(self::WAIT_SECONDS));
    }
}
