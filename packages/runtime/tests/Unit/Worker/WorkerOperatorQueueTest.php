<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use Amp\Future;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\State\Collection\StateChangeCollection;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Stream\PendingEmission;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Dispatch\SubscriptionQueue;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Ipc\Message\StateChangeBatch;
use Stewart\Runtime\Ipc\Message\StateSnapshot;
use Stewart\Runtime\Ipc\Message\WorkerReady;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Ipc\Wire\StateChangesFragment;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Kernel\WorkerKernel;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Tests\Fixtures\Protocol\OffHoldCaller;
use Stewart\Runtime\Tests\Fixtures\Time\RecordingProcessTimeZone;
use Stewart\Runtime\Time\ProcessTimeZone;
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

        $this->sendChange('sensor.busy', 'on', 'off');
        $this->timers->delay(Duration::seconds(30));
        $this->sendChange('sensor.watched', 'on', 'off');
        $this->timers->delay(Duration::seconds(30));
        EventLoopTicks::settleUntil(fn(): bool => $this->transport->listSentOfType(ServiceCallRequest::class) !== []);

        $this->sendChange('sensor.watched', 'off', 'on');
        $this->timers->delay(Duration::seconds(30));
        EventLoopTicks::settle();

        $busyCall = $this->transport->listSentOfType(ServiceCallRequest::class)[0];
        $this->transport->deliver(new ServiceCallResult($busyCall->correlationId, new ServiceResponse('script', 'record')));
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
        $this->transport->deliver(new StateSnapshot(EntityStatesFragment::fromCollection(EntityStateCollection::keyedByEntityId([])), revision: 1));
        EventLoopTicks::settleUntil(fn(): bool => $this->transport->listSentOfType(WorkerReady::class) !== []);
    }

    private function sendChange(string $entityId, string $from, string $to): void
    {
        $id = new EntityId($entityId);
        $change = new StateChange($id, new EntityState($id, $from), new EntityState($id, $to));

        $this->transport->deliver(new StateChangeBatch(StateChangesFragment::fromCollection(StateChangeCollection::fromChanges([$change]))));
        EventLoopTicks::settle();
    }
}
