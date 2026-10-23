<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use RuntimeException;
use Stewart\Contracts\Action\ActionResponse;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\ActionCallError;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppMetrics;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\HaCallSlots;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Config\ServiceCallPolicy;
use Stewart\Runtime\Ipc\Message\RequestFailed;
use Stewart\Runtime\Ipc\Message\ServiceCallRequest;
use Stewart\Runtime\Ipc\Message\ServiceCallResult;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(HaCallSlots::class)]
final class HaCallSlotsTest extends TestCase
{
    private Latch $held;

    protected function setUp(): void
    {
        $this->held = new Latch();
    }

    protected function tearDown(): void
    {
        $this->held->open();
        EventLoopTicks::settle();
    }

    public function testWorkerAtLimitIsRefused(): void
    {
        $slots = self::createSlots(new ServiceCallPolicy(perWorker: 1, total: 0));
        $worker = self::createHandle(0);

        $this->runHeldCall($slots, $worker, 'a');
        $this->runHeldCall($slots, $worker, 'b');

        self::assertSame(1, $slots->inFlight);
        self::assertSame('b', self::listRefusals($worker)[0]->correlationId->value);
        self::assertStringContainsString('this worker already has 1 calls to Home Assistant in flight', self::listRefusals($worker)[0]->message);
    }

    public function testOtherWorkersServedWhileOneIsAtLimit(): void
    {
        $slots = self::createSlots(new ServiceCallPolicy(perWorker: 1, total: 0));
        $this->runHeldCall($slots, self::createHandle(0), 'a');
        $other = self::createHandle(1);

        $this->runHeldCall($slots, $other, 'b');

        self::assertSame([], self::listRefusals($other), 'One noisy worker must not starve the others.');
        self::assertSame(2, $slots->inFlight);
    }

    public function testGlobalLimitBoundsEveryWorkerTogether(): void
    {
        $slots = self::createSlots(new ServiceCallPolicy(perWorker: 0, total: 2));
        $this->runHeldCall($slots, self::createHandle(0), 'a');
        $second = self::createHandle(1);
        $this->runHeldCall($slots, $second, 'b');

        $this->runHeldCall($slots, $second, 'c');

        self::assertSame(2, $slots->inFlight);
        self::assertStringContainsString('the broker already has 2 calls to Home Assistant in flight', self::listRefusals($second)[0]->message);

        $this->held->open();
        EventLoopTicks::settle();

        self::assertSame(0, $slots->inFlight);
        self::assertCount(1, self::listSentOfType($second, ServiceCallResult::class));
    }

    public function testReplacementStartsWithoutPredecessorCalls(): void
    {
        $slots = self::createSlots(new ServiceCallPolicy(perWorker: 1, total: 0));
        $this->runHeldCall($slots, self::createHandle(0), 'a');
        $replacement = self::createHandle(0);

        self::assertSame(0, $slots->countInFlightCallsFor($replacement));

        $this->runHeldCall($slots, $replacement, 'b');

        self::assertSame([], self::listRefusals($replacement));
    }

    public function testRefusalsAreCountedAndWarnedOnce(): void
    {
        $logger = new RecordingLogger();
        $slots = self::createSlots(new ServiceCallPolicy(perWorker: 1, total: 0), $logger);
        $worker = self::createHandle(0);

        $this->runHeldCall($slots, $worker, 'a');
        $this->runHeldCall($slots, $worker, 'b');
        $this->runHeldCall($slots, $worker, 'c');

        self::assertSame(2, $slots->refusedCalls);
        self::assertSame(['Refusing calls; Home Assistant is not keeping up'], $logger->listMessagesAt(LogLevel::WARNING));
    }

    public function testUnexpectedFailureAnswersUnreachable(): void
    {
        $slots = self::createSlots(new ServiceCallPolicy(perWorker: 1, total: 0));
        $worker = self::createHandle(0);

        $slots->runBounded($worker, self::createRequest('a'), static fn(): never => throw new RuntimeException('surprise'));
        EventLoopTicks::settle();

        self::assertSame(ActionCallError::Unreachable->value, self::listSentOfType($worker, RequestFailed::class)[0]->details->reason);
        self::assertSame(0, $slots->inFlight);
    }

    public function testCountedCallIsInFlightButNeverRefused(): void
    {
        $slots = self::createSlots(new ServiceCallPolicy(perWorker: 1, total: 0));
        $worker = self::createHandle(0);
        $this->runHeldCall($slots, $worker, 'a');
        $held = $this->held;

        $slots->runCounted($worker, self::createRequest('b'), static function () use ($held): ServiceCallResult {
            $held->waitUntilOpen();

            return new ServiceCallResult(new CorrelationId('b'), new ActionResponse('light', 'turn_on'));
        });

        self::assertSame(2, $slots->countInFlightCallsFor($worker));
        self::assertSame(0, $slots->refusedCalls);
    }

    private function runHeldCall(HaCallSlots $slots, WorkerHandle $worker, string $correlationId): void
    {
        $held = $this->held;

        $slots->runBounded($worker, self::createRequest($correlationId), static function () use ($held, $correlationId): ServiceCallResult {
            $held->waitUntilOpen();

            return new ServiceCallResult(new CorrelationId($correlationId), new ActionResponse('light', 'turn_on'));
        });
        EventLoopTicks::settle();
    }

    private static function createSlots(ServiceCallPolicy $policy, LoggerInterface $logger = new NullLogger()): HaCallSlots
    {
        $timers = new ManualTimers();

        return new HaCallSlots($policy, new AppMetrics(WorkerSlotCollection::fromList([self::createSlot(0), self::createSlot(1)]), $timers->clock), $timers->clock, $logger);
    }

    private static function createRequest(string $correlationId): ServiceCallRequest
    {
        return new ServiceCallRequest(new CorrelationId($correlationId), ResourceScope::forApp(new AppId('demo')), 'light', 'turn_on', [], null, false);
    }

    /** @return list<RequestFailed> */
    private static function listRefusals(WorkerHandle $handle): array
    {
        return self::listSentOfType($handle, RequestFailed::class);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     */
    private static function listSentOfType(WorkerHandle $handle, string $class): array
    {
        $transport = $handle->getTransport();
        self::assertInstanceOf(FakeWorkerTransport::class, $transport);

        return $transport->listSentOfType($class);
    }

    private static function createSlot(int $workerId): WorkerSlot
    {
        return new WorkerSlot(new WorkerId($workerId), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)]));
    }

    private static function createHandle(int $workerId): WorkerHandle
    {
        return new WorkerHandle(
            id: new WorkerId($workerId),
            process: new FakeWorkerProcess(),
            slot: self::createSlot($workerId),
            logger: new NullLogger(),
            outboxLimits: new OutboxLimits(100, 256),
        );
    }
}
