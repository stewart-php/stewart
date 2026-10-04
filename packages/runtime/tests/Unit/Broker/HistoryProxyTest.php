<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\HistoryError;
use Stewart\Contracts\Exception\HistoryException;
use Stewart\Contracts\History\HistoryWindow;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\HistoryProxy;
use Stewart\Runtime\Broker\OutboxLimits;
use Stewart\Runtime\Broker\WorkerHandle;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Ipc\Message\HistoryFailed;
use Stewart\Runtime\Ipc\Message\HistoryRequest;
use Stewart\Runtime\Ipc\Message\HistoryResult;
use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeWorkerProcess;
use Stewart\Runtime\Tests\Fixtures\Ipc\FakeWorkerTransport;
use Stewart\Testing\Time\EventLoopTicks;

#[CoversClass(HistoryProxy::class)]
final class HistoryProxyTest extends TestCase
{
    private FakeHaSession $session;

    private WorkerHandle $worker;

    protected function setUp(): void
    {
        $this->session = new FakeHaSession();
        $this->worker = new WorkerHandle(
            id: new WorkerId(0),
            process: new FakeWorkerProcess(),
            slot: new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)])),
            logger: new NullLogger(),
            outboxLimits: new OutboxLimits(100, 256),
        );
    }

    public function testStatesFromHomeAssistantAreSentBack(): void
    {
        $this->session->historicalStates = [new EntityState(new EntityId('light.hall'), 'off'), new EntityState(new EntityId('light.hall'), 'on')];

        $this->forwardRequest();

        $results = $this->listSentOfType(HistoryResult::class);
        self::assertCount(1, $results);
        self::assertSame('w0:7', $results[0]->correlationId->value);
        self::assertCount(2, $results[0]->states->collection);
    }

    public function testHomeAssistantFailureKeepsItsReason(): void
    {
        $this->session->historyFailure = HistoryException::recorderUnavailable(new EntityId('light.hall'));

        $this->forwardRequest();

        self::assertSame(HistoryError::RecorderUnavailable, $this->listSentOfType(HistoryFailed::class)[0]->getReason());
    }

    public function testDisconnectedSessionIsUnreachable(): void
    {
        $this->session->connected = false;

        $this->forwardRequest();

        self::assertSame(HistoryError::Unreachable, $this->listSentOfType(HistoryFailed::class)[0]->getReason());
        self::assertSame([], $this->listSentOfType(HistoryResult::class));
    }

    public function testUnexpectedFailureStillAnswers(): void
    {
        $this->session->historyFailure = new RuntimeException('surprise');

        $this->forwardRequest();

        self::assertSame(HistoryError::Unreachable, $this->listSentOfType(HistoryFailed::class)[0]->getReason());
    }

    private function forwardRequest(): void
    {
        new HistoryProxy($this->session, new NullLogger())->forward($this->worker, self::createRequest());
        EventLoopTicks::settle();
    }

    private static function createRequest(): HistoryRequest
    {
        return new HistoryRequest(
            new CorrelationId('w0:7'),
            ResourceScope::forApp(new AppId('demo')),
            new EntityId('light.hall'),
            new HistoryWindow(Instant::fromEpochMicroseconds(0), Instant::fromEpochMicroseconds(60_000_000)),
            false,
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return list<T>
     */
    private function listSentOfType(string $class): array
    {
        $transport = $this->worker->getTransport();
        self::assertInstanceOf(FakeWorkerTransport::class, $transport);

        return $transport->listSentOfType($class);
    }
}
