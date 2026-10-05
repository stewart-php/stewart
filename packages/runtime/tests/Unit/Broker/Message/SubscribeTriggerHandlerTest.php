<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Message\SubscribeTriggerHandler;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\SubscriptionRegistry;
use Stewart\Runtime\Broker\Trigger\TriggerSubscribers;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Ipc\Message\SubscribeTrigger;
use Stewart\Runtime\Ipc\Message\SubscriptionAck;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Testing\Time\EventLoopTicks;

#[CoversClass(SubscribeTriggerHandler::class)]
final class SubscribeTriggerHandlerTest extends TestCase
{
    public function testTriggerIsRegisteredUnderSharingKeyAndAcked(): void
    {
        $process = new FakeWorkerProcess();
        $session = new FakeHaSession();
        $registry = new SubscriptionRegistry([new TriggerSubscribers($session)]);
        $spec = TriggerSpec::fromSpec(HaTrigger::onSunset());
        $handle = new WorkerHandle(
            new WorkerId(0),
            $process,
            new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])),
            new NullLogger(),
            new OutboxLimits(10, 256),
        );

        new SubscribeTriggerHandler($registry, new NullLogger())->handle(
            $handle,
            new SubscribeTrigger(new SubscriptionId('w0:1'), ResourceScope::forApp(new AppId('demo')), $spec),
        );
        EventLoopTicks::settleUntil(static fn(): bool => $process->channel->listSentOfType(SubscriptionAck::class) !== []);

        self::assertTrue($process->channel->listSentOfType(SubscriptionAck::class)[0]->accepted);
        self::assertSame(1, $registry->findRoutes(SubscriptionKind::Trigger, $spec->getSharingKey())->listSubscriptionIdsForWorker(new WorkerId(0))->count());
        self::assertCount(1, $session->subscribedTriggers);
    }
}
