<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use Amp\Future;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\State\Collection\EntityStateCollection;
use Stewart\Contracts\Time\Clock;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Ipc\Message\Ping;
use Stewart\Runtime\Ipc\Message\Pong;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Ipc\Message\StateSnapshot;
use Stewart\Runtime\Ipc\Message\Unsubscribe;
use Stewart\Runtime\Ipc\Message\WorkerReady;
use Stewart\Runtime\Ipc\Wire\EntityStatesFragment;
use Stewart\Runtime\Kernel\SyntheticServices;
use Stewart\Runtime\Kernel\WorkerKernel;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Runtime\Tests\Fixtures\Ipc\TestBootstrap;
use Stewart\Runtime\Tests\Fixtures\Time\RecordingProcessTimeZone;
use Stewart\Runtime\Time\ProcessTimeZone;
use Stewart\Runtime\Worker\BrokerMessageReader;
use Stewart\Support\Time\RevoltTimers;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

use function Amp\async;

#[CoversClass(BrokerMessageReader::class)]
final class BrokerMessageReaderTest extends TestCase
{
    private FakeWorkerTransport $transport;

    private ManualTimers $timers;

    /** @var Future<string> */
    private Future $running;

    protected function setUp(): void
    {
        $this->transport = new FakeWorkerTransport();
        $this->timers = new ManualTimers();

        $kernel = new WorkerKernel(new SyntheticServices()
            ->withService(RevoltTimers::class, $this->timers)
            ->withService(Clock::class, $this->timers->clock)
            ->withService(ProcessTimeZone::class, new RecordingProcessTimeZone()));
        $this->transport->deliver(TestBootstrap::createForApps([]));

        /** @var Future<string> $running */
        $running = async(fn(): string => $kernel->run($this->transport));
        $this->running = $running;
        $this->transport->deliver(new StateSnapshot(EntityStatesFragment::fromCollection(EntityStateCollection::empty()), revision: 1));
        EventLoopTicks::settleUntil(fn(): bool => $this->transport->listSentOfType(WorkerReady::class) !== []);
    }

    protected function tearDown(): void
    {
        $this->transport->deliver(new Shutdown('test over', Duration::zero()));
        $this->running->await();
    }

    public function testUndecodableFrameIsDroppedAndReadingContinues(): void
    {
        $this->transport->fail(TransportException::undecodableFrame('mystery', 'no codec'));
        $this->transport->deliver(new Ping(1, $this->timers->clock->getNow()));
        EventLoopTicks::settleUntil(fn(): bool => $this->transport->listSentOfType(Pong::class) !== []);

        self::assertSame(['Dropped a broker message the worker could not decode'], $this->listLoggedAt(LogLevel::Warning));
    }

    public function testTransportFailureStopsWorkerAsBrokerLoss(): void
    {
        $this->transport->fail(TransportException::closed());

        self::assertStringContainsString('(broker gone: ' . TransportException::closed()->getMessage(), $this->running->await());
    }

    public function testClosedChannelStopsWorker(): void
    {
        $this->transport->hangUp();

        self::assertStringContainsString('(channel closed', $this->running->await());
    }

    public function testWrongDirectionMessageIsDroppedWithWarning(): void
    {
        $this->transport->deliver(new Unsubscribe(new SubscriptionId('w0:1')));
        $this->transport->deliver(new Ping(1, $this->timers->clock->getNow()));
        EventLoopTicks::settleUntil(fn(): bool => $this->transport->listSentOfType(Pong::class) !== []);

        self::assertSame(['Dropped a message that is not a broker message'], $this->listLoggedAt(LogLevel::Warning));
    }

    public function testThrowingHandlerIsLoggedAndReadingContinues(): void
    {
        $this->transport->refuseSendsOf(Pong::class, new RuntimeException('pipe full'));

        $this->transport->deliver(new Ping(1, $this->timers->clock->getNow()));
        $this->transport->deliver(new Ping(2, $this->timers->clock->getNow()));
        EventLoopTicks::settleUntil(fn(): bool => \count($this->listLoggedAt(LogLevel::Error)) === 2);

        self::assertSame(['Worker failed handling a broker message', 'Worker failed handling a broker message'], $this->listLoggedAt(LogLevel::Error));
    }

    public function testUnexpectedReceiveFailureStopsWorker(): void
    {
        $this->transport->fail(new RuntimeException('decoder exploded'));

        self::assertStringContainsString('(reading from the broker failed: decoder exploded', $this->running->await());
        self::assertSame(['Worker stopped reading broker messages'], $this->listLoggedAt(LogLevel::Error));
    }

    /** @return list<string> */
    private function listLoggedAt(LogLevel $level): array
    {
        $records = array_filter($this->transport->listSentOfType(LogRecord::class), static fn(LogRecord $record): bool => $record->level === $level);

        return array_values(array_map(static fn(LogRecord $record): string => $record->message, $records));
    }
}
