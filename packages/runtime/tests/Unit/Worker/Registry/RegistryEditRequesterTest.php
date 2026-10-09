<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\RegistryEditError;
use Stewart\Contracts\Exception\RegistryEditException;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Contracts\Registry\Update\EntityRegistryUpdate;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\RegistryEntityUpdateFailed;
use Stewart\Runtime\Ipc\Message\RegistryEntityUpdateRequest;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Ipc\FailingTransport;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Worker\ConnectionStatus;
use Stewart\Runtime\Worker\CorrelationIdSequence;
use Stewart\Runtime\Worker\PendingRequests;
use Stewart\Runtime\Worker\Registry\RegistryEditRequester;
use Stewart\Runtime\Worker\Subject\RegistryEditSubject;
use Stewart\Runtime\Worker\WorkerRegistryEditor;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;
use Throwable;

use function Amp\async;

#[CoversClass(RegistryEditRequester::class)]
#[CoversClass(RegistryEditSubject::class)]
#[CoversClass(WorkerRegistryEditor::class)]
final class RegistryEditRequesterTest extends TestCase
{
    use AssertsReason;

    private ManualTimers $timers;

    private PendingRequests $pending;

    private ConnectionStatus $connection;

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->pending = new PendingRequests(new CorrelationIdSequence(new WorkerId(0)));
        $this->connection = new ConnectionStatus();
    }

    public function testAppEditCarriesScopeAndAnswerIsEntry(): void
    {
        $transport = new NullTransport();
        $editor = $this->createEditor($transport)->forApp(new AppId('demo'));

        $edit = async(static fn(): RegisteredEntity => $editor->updateEntity('light.hall', new EntityRegistryUpdate()->withName('Hall')));
        EventLoopTicks::settle();

        $request = $transport->sent[0] ?? null;
        self::assertInstanceOf(RegistryEntityUpdateRequest::class, $request);
        self::assertSame('demo', $request->scope->wireValue());
        self::assertSame('Hall', $request->update->name?->name);

        $this->pending->resolve($request->correlationId, new RegisteredEntity(new EntityId('light.hall'), name: 'Hall'));

        $entity = $edit->await();
        self::assertInstanceOf(RegisteredEntity::class, $entity);
        self::assertSame('Hall', $entity->name);
    }

    public function testBrokerFailureReachesCaller(): void
    {
        $transport = new NullTransport();
        $editor = $this->createEditor($transport);

        $edit = async(static fn(): RegisteredEntity => $editor->updateEntity('light.gone', new EntityRegistryUpdate()->withHidden(true)));
        EventLoopTicks::settle();
        $request = $transport->sent[0] ?? null;
        self::assertInstanceOf(RegistryEntityUpdateRequest::class, $request);

        $failed = RegistryEntityUpdateFailed::fromException($request->correlationId, RegistryEditException::notFound($request->entityId));
        $this->pending->reject($request->correlationId, $failed->toException());

        $this->assertThrowsReason(RegistryEditError::NotFound, static fn() => $edit->await());
    }

    public function testEmptyUpdateFailsWithoutSending(): void
    {
        $transport = new NullTransport();

        $this->assertThrowsReason(
            RegistryEditError::NothingToUpdate,
            fn() => $this->createEditor($transport)->updateEntity('light.hall', new EntityRegistryUpdate()),
        );
        self::assertSame([], $transport->sent);
    }

    public function testDisconnectedFailsWithoutSending(): void
    {
        $transport = new NullTransport();
        $this->connection->markLost();

        $this->assertThrowsReason(RegistryEditError::Unreachable, fn() => $this->editFrom($transport));
        self::assertSame([], $transport->sent);
    }

    #[DataProvider('provideSendFailures')]
    public function testSendFailureIsMappedToItsReason(Throwable $failure, RegistryEditError $reason): void
    {
        $this->assertThrowsReason($reason, fn() => $this->editFrom(new FailingTransport($failure)));
    }

    /** @return iterable<string, array{Throwable, RegistryEditError}> */
    public static function provideSendFailures(): iterable
    {
        yield 'a request that cannot be encoded' => [TransportException::unencodable(RegistryEntityUpdateRequest::class, new RuntimeException('NAN')), RegistryEditError::Rejected];
        yield 'a channel that is gone' => [TransportException::sendFailed(new RuntimeException('broken pipe')), RegistryEditError::Unreachable];
    }

    public function testSilentBrokerTimesOut(): void
    {
        async(fn() => $this->timers->delay(Duration::seconds(1)))->ignore();

        $this->assertThrowsReason(RegistryEditError::TimedOut, fn() => $this->editFrom(new NullTransport()));
        self::assertSame(0, $this->pending->failAll('nothing left'));
    }

    private function editFrom(Transport $transport): void
    {
        $this->createEditor($transport)->updateEntity('light.hall', new EntityRegistryUpdate()->withName('Hall'));
    }

    private function createEditor(Transport $transport): WorkerRegistryEditor
    {
        return new WorkerRegistryEditor(
            new RegistryEditRequester($transport, $this->pending, $this->connection, $this->timers, Duration::seconds(1)),
            ResourceScope::shared(),
        );
    }
}
