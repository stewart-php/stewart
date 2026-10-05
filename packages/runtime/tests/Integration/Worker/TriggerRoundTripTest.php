<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Integration\Worker;

use Amp\Future;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\Trigger\TriggerRejections;
use Stewart\Runtime\Broker\Trigger\TriggerSubscribers;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Ipc\Wire\IpcCodec;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Broker\BrokerKernelFixture;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\WorkerPoolFixture;
use Stewart\Runtime\Tests\Fixtures\Protocol\SunsetWatcher;
use Stewart\Runtime\Tests\Fixtures\Worker\InMemoryWorkerSpawner;
use Stewart\Runtime\Worker\Context\DispatchStreams;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;

use function Amp\async;

#[CoversClass(TriggerSubscribers::class)]
#[CoversClass(TriggerRejections::class)]
#[CoversClass(DispatchStreams::class)]
final class TriggerRoundTripTest extends TestCase
{
    private const float WAIT_SECONDS = 5;

    public function testSharedTriggerReachesBothAppsUntilStop(): void
    {
        $session = new FakeHaSession();
        $logger = new RecordingLogger();
        $pools = WorkerPoolFixture::createWorkerPool(new InMemoryWorkerSpawner(IpcCodec::createForWorkerBootstrap()), logger: $logger);
        $broker = BrokerKernelFixture::boot(
            $session,
            $pools,
            WorkerSlotCollection::fromWorkerSlots([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([
                new AppDefinition(new AppId('dusk-one'), SunsetWatcher::class),
                new AppDefinition(new AppId('dusk-two'), SunsetWatcher::class),
            ]))]),
            AppIdCollection::fromIds([new AppId('dusk-one'), new AppId('dusk-two')]),
            $logger,
            ['shutdown_grace' => '1s'],
        );
        $sunset = TriggerSpec::fromSpec(HaTrigger::onSunset());
        $rejected = TriggerSpec::fromSpec(SunsetWatcher::REJECTED_TRIGGER);

        /** @var Future<null> $running */
        $running = async($broker->lifecycle->run(...));
        EventLoopTicks::settleUntil(static fn(): bool => \in_array('Worker ready', $logger->listMessagesAt('info'), true));

        self::assertCount(2, $session->subscribedTriggers, 'Two apps watching the same two specs make two Home Assistant subscriptions.');

        $session->rejectTrigger($rejected, 'Invalid trigger');
        EventLoopTicks::settleUntil(static fn(): bool => \count($logger->listMessagesAt('error')) === 2);

        $calls = $session->waitForNextCall();
        $session->fireTrigger($sunset, new TriggerEvent(['platform' => 'sun', 'idx' => '0']));
        $calls->await(new TimeoutCancellation(self::WAIT_SECONDS));
        EventLoopTicks::settleUntil(static fn(): bool => $session->calls === 2);

        self::assertSame(2, $session->calls);
        self::assertSame(['Broker rejected a subscription', 'Broker rejected a subscription'], $logger->listMessagesAt('error'));

        $broker->run->stop('test done');
        $running->await(new TimeoutCancellation(self::WAIT_SECONDS));

        $released = array_values(array_filter(
            $session->unsubscribedTriggers,
            static fn(TriggerSpec $spec): bool => $spec->getSharingKey() === $sunset->getSharingKey(),
        ));
        self::assertCount(1, $released);
    }
}
