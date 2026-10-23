<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use Amp\Future;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Action\ActionResponse;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Dispatch\SubscriptionQueue;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Ipc\Message\StateSnapshot;
use Stewart\Runtime\Ipc\Message\StateUpdateBatch;
use Stewart\Runtime\Ipc\Message\WorkerReady;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\Wire\StateUpdatesFragment;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Kernel\WorkerKernel;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Tests\Fixtures\Protocol\OffHoldCaller;
use Stewart\Runtime\Tests\Fixtures\Time\RecordingProcessTimeZone;
use Stewart\Runtime\Time\ProcessTimeZone;
use Stewart\Support\State\Collection\StateUpdateCollection;
use Stewart\Support\State\StateUpdate;
use Stewart\Support\Stream\PendingEmission;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

use function Amp\async;

#[CoversClass(SubscriptionQueue::class)]
#[CoversClass(PendingEmission::class)]
final class WorkerOperatorQueueTest extends TestCase
{
    private FakeWorkerTransport $transport;

    private ManualTimers $timers;

    /** @var Future<string>|null */
    private ?Future $running = null;

    protected function setUp(): void
    {
        $this->transport = new FakeWorkerTransport();
        $this->timers = new ManualTimers();
    }

    protected function tearDown(): void
    {
        $this->transport->deliver(new Shutdown('test over', Duration::zero()));
        $this->running?->await();
    }

    public function testHoldBrokenDuringServiceCallDoesNotFire(): void
    {
        $this->startWorker();

        $this->sendUpdate('sensor.busy', 'off');
        $this->timers->delay(Duration::seconds(30));
        $this->sendUpdate('sensor.watched', 'off');
        $this->timers->delay(Duration::seconds(30));
        EventLoopTicks::settleUntil(fn(): bool => $this->transport->listSentOfType(ServiceCallRequest::class) !== []);

        $this->sendUpdate('sensor.watched', 'on');
        $this->timers->delay(Duration::seconds(30));
        EventLoopTicks::settle();

        $busyCall = $this->transport->listSentOfType(ServiceCallRequest::class)[0];
        $this->transport->deliver(new ServiceCallResult($busyCall->correlationId, new ActionResponse('script', 'record')));
        EventLoopTicks::settle(5);

        $calls = array_map(static fn(ServiceCallRequest $request): mixed => $request->data['entity'] ?? null, $this->transport->listSentOfType(ServiceCallRequest::class));
        self::assertSame(['sensor.busy'], $calls, 'The watched sensor came back on while the first call was in flight.');
    }

    private function startWorker(): void
    {
        $kernel = new WorkerKernel(new SyntheticServices()
            ->withService(RevoltTimers::class, $this->timers)
            ->withService(Clock::class, $this->timers->clock)
            ->withService(ProcessTimeZone::class, new RecordingProcessTimeZone()));
        $this->transport->deliver(TestBootstrap::createForApps(
            [new WorkerApp(id: new AppId('off-hold-caller'), class: OffHoldCaller::class, options: [])],
            callTimeout: Duration::minutes(10),
        ));

        /** @var Future<string> $running */
        $running = async(fn(): string => $kernel->run($this->transport));
        $this->running = $running;
        $this->transport->deliver(new StateSnapshot(EntityStatesFragment::fromCollection(EntityStateCollection::keyedByEntityId([
            new EntityState(new EntityId('sensor.busy'), 'on'),
            new EntityState(new EntityId('sensor.watched'), 'on'),
        ])), revision: 1));
        EventLoopTicks::settleUntil(fn(): bool => $this->transport->listSentOfType(WorkerReady::class) !== []);
    }

    private function sendUpdate(string $entityId, string $to): void
    {
        $id = new EntityId($entityId);

        $this->transport->deliver(new StateUpdateBatch(StateUpdatesFragment::fromCollection(StateUpdateCollection::fromList([new StateUpdate($id, new EntityState($id, $to))]))));
        EventLoopTicks::settle();
    }
}
