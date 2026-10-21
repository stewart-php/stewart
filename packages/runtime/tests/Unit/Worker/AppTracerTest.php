<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Schedule\ScheduledRun;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\Time\Instant;
use Stewart\Contracts\Topic\TopicEvent;
use Stewart\Runtime\Dispatch\RegisteredSubscription;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Logging\LogVerbosityPolicy;
use Stewart\Runtime\Model\AppVerbosity;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionId;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Schedule\ScheduleOrigin;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Worker\AppTracer;
use Stewart\Runtime\Worker\StderrFallback;
use Stewart\Runtime\Worker\WorkerLogger;
use Stewart\Support\Stream\SubscriptionScope;
use Stewart\Testing\Schedule\ManualScheduledTask;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(AppTracer::class)]
final class AppTracerTest extends TestCase
{
    private NullTransport $transport;

    private AppTracer $tracer;

    protected function setUp(): void
    {
        $this->transport = new NullTransport();
        $verbosity = new LogVerbosityPolicy(LogLevel::Warning, ['porch' => new AppVerbosity(trace: true)]);
        $this->tracer = new AppTracer(new WorkerLogger($this->transport, new StderrFallback(new WorkerId(0)), ResourceScope::shared(), $verbosity), $verbosity);
    }

    public function testReceivedStateChangeNamesTheSubscriptionAndTheChange(): void
    {
        $this->tracer->traceEventReceived(self::createSubscription('porch', Selector::exact('light.porch')), self::createChange('light.porch', 'off', 'on'));

        $record = $this->findOnlyRecord();
        self::assertSame('Event received', $record->message);
        self::assertSame(LogLevel::Debug, $record->level);
        self::assertTrue($record->trace, 'A trace passes although the global level is warning.');
        self::assertSame('porch', (string) $record->scope);
        self::assertSame(['subscription' => 'state_change exact:light.porch', 'id' => 'w0:1', 'change' => 'off → on'], $record->context);
    }

    public function testPatternSubscriptionAlsoNamesTheEntity(): void
    {
        $this->tracer->traceEventReceived(self::createSubscription('porch', Selector::glob('light.*')), self::createChange('light.porch', null, 'on'));

        self::assertSame(['subscription' => 'state_change glob:light.*', 'id' => 'w0:1', 'change' => 'none → on', 'entity' => 'light.porch'], $this->findOnlyRecord()->context);
    }

    public function testTopicNamesItsPublisher(): void
    {
        $subscription = self::createSubscription('porch', Selector::exact('house.mode'), SubscriptionKind::Topic);

        $this->tracer->traceSkippedWhilePaused($subscription, new TopicEvent('house.mode', 'away', new AppId('presence'), Instant::fromEpochMicroseconds(0)));

        $record = $this->findOnlyRecord();
        self::assertSame('Event skipped while paused', $record->message);
        self::assertSame(['subscription' => 'topic exact:house.mode', 'id' => 'w0:1', 'topic' => 'house.mode', 'from' => 'presence'], $record->context);
    }

    public function testHandlerCallAndScheduleRunAreTraced(): void
    {
        $this->tracer->traceHandlerCalled(self::createSubscription('porch', Selector::exact('light.porch')));
        $at = Instant::fromEpochMicroseconds(1_000_000);
        $this->tracer->traceScheduleRun(new ScheduleOrigin(ResourceScope::forApp(new AppId('porch')), 'task-1', 'daily at 07:00'), new ScheduledRun(new ManualScheduledTask('task-1', new ManualTimers(), static fn(): ?Instant => null, static function (): void {}), $at, $at, 0));

        self::assertSame(['Handler called', 'Schedule ran'], array_map(static fn(LogRecord $record): string => $record->message, $this->listRecords()));
        self::assertSame('schedule task-1 (daily at 07:00)', $this->listRecords()[1]->context['schedule'] ?? null);
    }

    public function testAppWithoutTracingSendsNothing(): void
    {
        $this->tracer->traceEventReceived(self::createSubscription('hall', Selector::exact('light.hall')), self::createChange('light.hall', 'off', 'on'));
        $this->tracer->traceHandlerCalled(self::createSubscription('hall', Selector::exact('light.hall')));

        self::assertSame([], $this->listRecords());
    }

    private function findOnlyRecord(): LogRecord
    {
        $records = $this->listRecords();
        self::assertCount(1, $records);

        return $records[0];
    }

    /** @return list<LogRecord> */
    private function listRecords(): array
    {
        return array_values(array_filter($this->transport->sent, static fn(object $message): bool => $message instanceof LogRecord));
    }

    private static function createSubscription(string $appId, Selector $selector, SubscriptionKind $kind = SubscriptionKind::StateChange): RegisteredSubscription
    {
        return new RegisteredSubscription(SubscriptionId::fromString('w0:1'), ResourceScope::forApp(new AppId($appId)), $kind, $selector, new SubscriptionScope(), static function (): void {});
    }

    private static function createChange(string $entityId, ?string $from, string $to): StateChange
    {
        $id = new EntityId($entityId);

        return new StateChange($id, $from === null ? null : new EntityState($id, $from), new EntityState($id, $to));
    }
}
