<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\WaitError;
use Stewart\Contracts\Exception\WaitException;
use Stewart\Contracts\Selector\Selector;
use Stewart\Runtime\Dispatch\RegisteredSubscription;
use Stewart\Runtime\Ipc\Message\AppFailed;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Schedule\ScheduleOrigin;
use Stewart\Runtime\Scope\ScopeLifecycle;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Worker\AppActivityCounters;
use Stewart\Runtime\Worker\AppActivityRecorder;
use Stewart\Runtime\Worker\AppFailureReporter;
use Stewart\Runtime\Worker\HandlerFailureSampler;
use Stewart\Runtime\Worker\StderrFallback;
use Stewart\Runtime\Worker\WorkerLogger;
use Stewart\Support\Stream\SubscriptionScope;

#[CoversClass(AppActivityRecorder::class)]
final class AppActivityRecorderTest extends TestCase
{
    private NullTransport $transport;

    private ScopeLifecycle $scopes;

    private AppActivityRecorder $recorder;

    protected function setUp(): void
    {
        $this->transport = new NullTransport();
        $this->scopes = new ScopeLifecycle();
        $this->scopes->activateScope(self::createScope());
        $counters = new AppActivityCounters();
        $this->recorder = new AppActivityRecorder(
            new AppFailureReporter($this->transport, new StderrFallback(new WorkerId(0)), $counters, new HandlerFailureSampler()),
            $counters,
            new WorkerLogger($this->transport, new StderrFallback(new WorkerId(0)), ResourceScope::shared()),
            $this->scopes,
        );
    }

    public function testWaitAbandonedByStoppingTheAppIsNotReported(): void
    {
        $this->scopes->releaseScope(self::createScope());

        $this->recorder->handlerFailed(self::createSubscription(), self::createAbandonedWait());
        $this->recorder->scheduledRunFailed(self::createScheduleOrigin(), self::createAbandonedWait());

        self::assertSame([], $this->listSentFailures());
    }

    public function testWaitAbandonedWhileTheAppRunsIsReported(): void
    {
        $this->recorder->handlerFailed(self::createSubscription(), WaitException::createForReason(WaitError::Abandoned));
        $this->recorder->scheduledRunFailed(self::createScheduleOrigin(), WaitException::createForReason(WaitError::Abandoned));

        self::assertCount(2, $this->listSentFailures());
    }

    /** @return list<AppFailed> */
    private function listSentFailures(): array
    {
        return array_values(array_filter($this->transport->sent, static fn(object $message): bool => $message instanceof AppFailed));
    }

    private static function createAbandonedWait(): WaitException
    {
        return WaitException::createForReason(WaitError::Abandoned);
    }

    private static function createSubscription(): RegisteredSubscription
    {
        return new RegisteredSubscription(new SubscriptionId('w0:1'), self::createScope(), SubscriptionKind::StateChange, Selector::exact('lock.front'), new SubscriptionScope(), static fn() => null);
    }

    private static function createScheduleOrigin(): ScheduleOrigin
    {
        return new ScheduleOrigin(self::createScope(), 'w0:2', 'after 5s');
    }

    private static function createScope(): ResourceScope
    {
        return ResourceScope::forApp(new AppId('demo'));
    }
}
