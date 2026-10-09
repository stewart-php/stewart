<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker\Exposure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\ExposureError;
use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Contracts\Exposure\ExposedState;
use Stewart\Contracts\Exposure\ExposedStateChange;
use Stewart\Contracts\Exposure\SensorConfig;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\ExposeEntityRequest;
use Stewart\Runtime\Ipc\Message\ExposeEntityResult;
use Stewart\Runtime\Ipc\Message\UpdateExposedEntityRequest;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Ipc\FailingTransport;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Worker\CorrelationIdSequence;
use Stewart\Runtime\Worker\Exposure\ExposureRequester;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Runtime\Worker\Subject\ExposeEntitySubject;
use Stewart\Runtime\Worker\Subject\ExposureChangeSubject;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;
use Throwable;

use function Amp\async;

#[CoversClass(ExposureRequester::class)]
#[CoversClass(ExposeEntitySubject::class)]
#[CoversClass(ExposureChangeSubject::class)]
final class ExposureRequesterTest extends TestCase
{
    use AssertsReason;

    private ManualTimers $timers;

    private PendingRequests $pending;

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->pending = new PendingRequests(new CorrelationIdSequence(new WorkerId(0)));
    }

    public function testBrokerSnapshotIsReturned(): void
    {
        $transport = new NullTransport();
        $requester = $this->createRequester($transport);

        $exposure = async(static fn() => $requester->requestExposure(self::createScope(), new ExposedEntityKey('level'), new SensorConfig(), null, new ExposedStateChange()));
        EventLoopTicks::settle();

        $request = $transport->sent[0] ?? null;
        self::assertInstanceOf(ExposeEntityRequest::class, $request);
        $snapshot = new ExposedEntitySnapshot(new EntityId('sensor.demo_level'), new ExposedState(3), [], true);
        $this->pending->resolve($request->correlationId, new ExposeEntityResult($request->correlationId, $snapshot));

        self::assertSame($snapshot, $exposure->await());
    }

    public function testBrokerRefusalIsThrown(): void
    {
        $transport = new NullTransport();
        $requester = $this->createRequester($transport);

        $update = async(static fn() => $requester->requestUpdate(self::createScope(), new ExposedEntityKey('level'), new ExposedStateChange(new ExposedState(3))));
        EventLoopTicks::settle();

        $request = $transport->sent[0] ?? null;
        self::assertInstanceOf(UpdateExposedEntityRequest::class, $request);
        $this->pending->reject($request->correlationId, ExposureException::stateInvalid('Not a number.'));

        $this->assertThrowsReason(ExposureError::StateInvalid, static fn() => $update->await());
    }

    #[DataProvider('provideSendFailures')]
    public function testSendFailureIsMappedToItsReason(Throwable $failure, ExposureError $reason): void
    {
        $requester = $this->createRequester(new FailingTransport($failure));

        $this->assertThrowsReason($reason, static fn() => $requester->requestRemoval(self::createScope(), new ExposedEntityKey('level')));
    }

    /** @return iterable<string, array{Throwable, ExposureError}> */
    public static function provideSendFailures(): iterable
    {
        yield 'a request that cannot be encoded' => [TransportException::unencodable(UpdateExposedEntityRequest::class, new RuntimeException('NAN')), ExposureError::StateInvalid];
        yield 'a channel that is gone' => [TransportException::sendFailed(new RuntimeException('broken pipe')), ExposureError::Unreachable];
    }

    public function testSilentBrokerTimesOut(): void
    {
        $requester = $this->createRequester(new NullTransport());
        async(fn() => $this->timers->delay(Duration::seconds(1)))->ignore();

        $this->assertThrowsReason(ExposureError::TimedOut, static fn() => $requester->requestRemoval(self::createScope(), new ExposedEntityKey('level')));
        self::assertSame(0, $this->pending->failAll('nothing left'));
    }

    private static function createScope(): ResourceScope
    {
        return ResourceScope::forApp(new AppId('demo'));
    }

    private function createRequester(Transport $transport): ExposureRequester
    {
        return new ExposureRequester($transport, $this->pending, $this->timers, Duration::seconds(1));
    }
}
