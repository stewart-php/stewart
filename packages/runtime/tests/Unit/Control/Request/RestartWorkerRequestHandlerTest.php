<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Control\Request;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;
use Stewart\Runtime\Broker\WorkerSlot;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Stewart\Runtime\Control\Protocol\Frame\RestartWorkerRequest;
use Stewart\Runtime\Control\Request\RestartWorkerRequestHandler;
use Stewart\Runtime\Exception\WorkerError;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Control\BrokerStateFixture;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(RestartWorkerRequestHandler::class)]
final class RestartWorkerRequestHandlerTest extends TestCase
{
    use AssertsReason;

    private BrokerStateFixture $broker;

    private RestartWorkerRequestHandler $handler;

    protected function setUp(): void
    {
        $this->broker = new BrokerStateFixture();
        $this->handler = new RestartWorkerRequestHandler(
            WorkerSlotCollection::fromList([new WorkerSlot(new WorkerId(0), AppDefinitionCollection::keyedByAppId([]))]),
            $this->broker->getPool(),
        );
    }

    protected function tearDown(): void
    {
        $this->broker->stopEverything();
    }

    public function testRestartIsReportedAsChangedOnce(): void
    {
        $this->broker->startWorker(0);

        self::assertEquals(new CommandResult(true, 'Worker 0 is restarting.', null), $this->handler->answerRequest(new RestartWorkerRequest('0')));
        self::assertEquals(new CommandResult(false, 'Worker 0 is already starting or stopping.', null), $this->handler->answerRequest(new RestartWorkerRequest('0')));
    }

    public function testWorkerOutsideThePoolIsUnknown(): void
    {
        $this->assertThrowsReason(WorkerError::Unknown, fn() => $this->handler->answerRequest(new RestartWorkerRequest('4')));
    }
}
