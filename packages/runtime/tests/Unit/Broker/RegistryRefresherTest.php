<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\RegistryRefresher;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Tests\Fixtures\Broker\FakeHaSession;
use Stewart\Testing\Async\Latch;
use Stewart\Testing\Time\EventLoopTicks;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(RegistryRefresher::class)]
final class RegistryRefresherTest extends TestCase
{
    private FakeHaSession $session;

    private ManualTimers $timers;

    private RegistryRefresher $refresher;

    protected function setUp(): void
    {
        $this->session = FakeHaSession::createOpened();
        $this->timers = new ManualTimers();
        $this->refresher = new RegistryRefresher($this->session, new WorkerSlotRegistry(), $this->timers, new NullLogger());
    }

    public function testBurstRefreshesOnceAfterQuietPeriod(): void
    {
        $this->refresher->scheduleRefreshFor(new HaEvent('entity_registry_updated'));
        $this->timers->delay(Duration::milliseconds(500));
        $this->refresher->scheduleRefreshFor(new HaEvent('device_registry_updated'));
        $this->timers->delay(Duration::milliseconds(999));
        EventLoopTicks::settle();

        self::assertSame(0, $this->session->registryRefreshes, 'The second event restarts the quiet period.');

        $this->timers->delay(Duration::milliseconds(1));
        EventLoopTicks::settle();

        self::assertSame(1, $this->session->registryRefreshes);
    }

    public function testUnrelatedEventSchedulesNothing(): void
    {
        $this->refresher->scheduleRefreshFor(new HaEvent('state_changed'));
        $this->refresher->scheduleRefreshFor(new HaEvent('zha_event'));

        self::assertSame(0, $this->timers->countPendingTimers());
    }

    public function testEventDuringRefreshRefreshesAgain(): void
    {
        $gate = new Latch();
        $this->session->registryRefreshGate = $gate;

        $this->refresher->scheduleRefreshFor(new HaEvent('area_registry_updated'));
        $this->timers->delay(Duration::seconds(1));
        EventLoopTicks::settleUntil(static fn(): bool => $gate->countWaiters() === 1);

        $this->refresher->scheduleRefreshFor(new HaEvent('label_registry_updated'));
        $this->timers->delay(Duration::seconds(1));
        $gate->open();
        EventLoopTicks::settle();

        self::assertSame(2, $this->session->registryRefreshes);
        self::assertSame(3, $this->session->registryRevision);
    }
}
