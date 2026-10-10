<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerMessageReader;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\Shutdown;
use Stewart\Runtime\Ipc\Message\Unsubscribe;
use Stewart\Runtime\Ipc\Message\WorkerMessage;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Testing\Logging\RecordedLog;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;

#[CoversClass(WorkerMessageReader::class)]
final class WorkerMessageReaderTest extends TestCase
{
    private FakeWorkerProcess $process;

    private RecordingLogger $logger;

    /** @var list<WorkerMessage> */
    private array $received = [];

    /** @var list<string> */
    private array $gone = [];

    private bool $failOnMessage = false;

    private bool $failOnGone = false;

    protected function setUp(): void
    {
        $this->process = new FakeWorkerProcess();
        $this->logger = new RecordingLogger();

        new WorkerMessageReader($this->logger)->readMessagesInBackground(
            new WorkerHandle(new WorkerId(0), $this->process, new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])), new NullLogger(), new OutboxLimits(10, 256)),
            $this->receive(...),
            function (string $reason): void {
                $this->gone[] = $reason;

                if ($this->failOnGone) {
                    throw new RuntimeException('exit handler blew up');
                }
            },
        );
    }

    public function testWorkerMessagesAreForwardedInOrder(): void
    {
        $this->process->channel->deliver(new Unsubscribe(new SubscriptionId('w0:0')));
        $this->process->channel->deliver(new Unsubscribe(new SubscriptionId('w0:1')));
        EventLoopTicks::settleUntil(fn(): bool => \count($this->received) === 2);

        self::assertEquals([new Unsubscribe(new SubscriptionId('w0:0')), new Unsubscribe(new SubscriptionId('w0:1'))], $this->received);
        self::assertSame([], $this->gone);
    }

    public function testAnythingThatIsNotAWorkerMessageIsSkipped(): void
    {
        $this->process->channel->deliver(new Shutdown('wrong way round', Duration::zero()));
        $this->process->channel->deliver(new Unsubscribe(new SubscriptionId('w0:0')));
        EventLoopTicks::settleUntil(fn(): bool => \count($this->received) === 1);

        self::assertEquals([new Unsubscribe(new SubscriptionId('w0:0'))], $this->received);
    }

    public function testThrowingHandlerIsLoggedAndReadingContinues(): void
    {
        $this->failOnMessage = true;

        $this->process->channel->deliver(new Unsubscribe(new SubscriptionId('w0:0')));
        $this->process->channel->deliver(new Unsubscribe(new SubscriptionId('w0:1')));
        EventLoopTicks::settleUntil(fn(): bool => \count($this->received) === 2 && \count($this->logger->listMessagesAt('error')) === 2);

        self::assertCount(2, $this->received);
        self::assertSame(['Failed handling a worker message', 'Failed handling a worker message'], $this->logger->listMessagesAt('error'));
        self::assertSame([], $this->gone);
    }

    public function testUndecodableFrameIsDroppedAndReadingContinues(): void
    {
        EventLoopTicks::settle();
        $this->process->channel->fail(TransportException::undecodableFrame('mystery', 'no codec'));
        EventLoopTicks::settle();
        $this->process->channel->deliver(new Unsubscribe(new SubscriptionId('w0:0')));
        EventLoopTicks::settleUntil(fn(): bool => \count($this->received) === 1);

        self::assertCount(1, $this->received);
        self::assertSame(['Dropped a worker message the broker could not decode'], $this->logger->listMessagesAt('error'));
        self::assertSame([], $this->gone);
    }

    public function testClosedChannelStopsTheReader(): void
    {
        $this->process->crash();
        EventLoopTicks::settleUntil(fn(): bool => $this->gone !== []);

        self::assertSame(['closed the channel'], $this->gone);
    }

    public function testThrowingExitHandlerIsLogged(): void
    {
        $this->failOnGone = true;

        $this->process->crash();
        EventLoopTicks::settleUntil(fn(): bool => $this->gone !== []);

        self::assertSame(['Failed handling a worker exit'], $this->logger->listMessagesAt('error'));
        self::assertSame('closed the channel', $this->logger->records->findFirstWhere(static fn(RecordedLog $log): bool => $log->level === 'error')?->context['reason']);
    }

    public function testClosedChannelIsLoggedAtDebug(): void
    {
        $this->process->crash();
        EventLoopTicks::settleUntil(fn(): bool => $this->gone !== []);

        self::assertSame(['Stopped reading from worker'], $this->logger->listMessagesAt('debug'));
        self::assertSame('closed the channel', $this->logger->records->getFirst()?->context['reason']);
    }

    public function testFailingChannelIsLoggedWithItsException(): void
    {
        $failure = TransportException::closed();

        EventLoopTicks::settle();
        $this->process->channel->fail($failure);
        EventLoopTicks::settleUntil(fn(): bool => $this->gone !== []);

        self::assertSame(['Stopped reading from worker'], $this->logger->listMessagesAt('debug'));
        self::assertSame($failure, $this->logger->records->getFirst()?->context['exception']);
    }

    public function testPeerDisconnectCountsAsClosedChannel(): void
    {
        EventLoopTicks::settle();
        $this->process->channel->fail(TransportException::peerDisconnected(new RuntimeException('channel closed')));
        EventLoopTicks::settleUntil(fn(): bool => $this->gone !== []);

        self::assertSame(['closed the channel'], $this->gone);
        self::assertSame('closed the channel', $this->logger->records->getFirst()?->context['reason']);
    }

    public function testFailingChannelStopsTheReaderWithItsReason(): void
    {
        EventLoopTicks::settle();
        $this->process->channel->fail(TransportException::closed());
        EventLoopTicks::settleUntil(fn(): bool => $this->gone !== []);

        self::assertSame([TransportException::closed()->getMessage()], $this->gone);
    }

    private function receive(WorkerMessage $message): void
    {
        $this->received[] = $message;

        if ($this->failOnMessage) {
            throw new RuntimeException('handler blew up');
        }
    }
}
