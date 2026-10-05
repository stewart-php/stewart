<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Schedule\ScheduledTask;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Stream\SubscriptionScope;
use Stewart\Contracts\Subscription;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\SubscriptionKind;
use Stewart\Runtime\Schedule\WorkerScheduler;
use Stewart\Runtime\Tests\Fixtures\Worker\AppResourcesFixture;
use Stewart\Runtime\Worker\AppResources;
use Stewart\Sun\UnlocatedSunCalendar;

#[CoversClass(AppResources::class)]
final class AppResourcesTest extends TestCase
{
    private AppResourcesFixture $fixture;

    private AppResources $resources;

    private int $runs = 0;

    protected function setUp(): void
    {
        $this->fixture = new AppResourcesFixture();
        $this->resources = $this->fixture->resources;
        $this->runs = 0;
    }

    public function testGoingLiveStartsOnlyThatAppsSchedules(): void
    {
        $this->scheduleEveryTenSeconds('demo');
        $this->scheduleEveryTenSeconds('other');

        $this->resources->activateScope(ResourceScope::forApp(new AppId('demo')));
        $this->fixture->timers->delay(Duration::seconds(10));

        self::assertSame(1, $this->runs);
    }

    public function testReleaseDropsSubscriptionsAndSchedules(): void
    {
        $this->resources->activateScope(ResourceScope::forApp(new AppId('demo')));
        $subscription = $this->subscribe('demo');
        $task = $this->scheduleEveryTenSeconds('demo');
        $kept = $this->subscribe('other');

        $this->resources->releaseScope(ResourceScope::forApp(new AppId('demo')));

        self::assertFalse($subscription->isActive());
        self::assertFalse($task->isActive());
        self::assertTrue($kept->isActive());
        self::assertFalse($this->subscribe('demo')->isActive(), 'Released means released, for a late fiber too.');
        self::assertFalse($this->scheduleEveryTenSeconds('demo')->isActive());
    }

    public function testReleaseAllRefusesNewRegistrations(): void
    {
        $this->resources->activateScope(ResourceScope::forApp(new AppId('demo')));
        $this->subscribe('demo');
        $this->scheduleEveryTenSeconds('demo');

        $this->resources->releaseAll();

        self::assertSame(0, $this->fixture->dispatcher->countFor(ResourceScope::forApp(new AppId('demo'))));
        self::assertSame(0, $this->fixture->schedules->count());
        self::assertSame(0, $this->fixture->timers->countPendingTimers());
        self::assertFalse($this->subscribe('late')->isActive());
    }

    public function testActivationAfterReleaseAllStartsNothing(): void
    {
        $subscription = $this->subscribe('demo');
        $this->scheduleEveryTenSeconds('demo');
        $this->resources->releaseAll();

        $this->resources->activateScope(ResourceScope::forApp(new AppId('demo')));
        $this->fixture->timers->delay(Duration::seconds(10));

        self::assertFalse($this->fixture->scopes->isLive(ResourceScope::forApp(new AppId('demo'))));
        self::assertFalse($subscription->isActive());
        self::assertSame(0, $this->runs);
    }

    private function subscribe(string $appId): Subscription
    {
        $scope = new SubscriptionScope();
        $subscription = $this->fixture->dispatcher->register(ResourceScope::forApp(new AppId($appId)), SubscriptionKind::Topic, Selector::any(), $scope, static function (): void {});
        $scope->attachedTo($subscription);

        return $subscription;
    }

    private function scheduleEveryTenSeconds(string $appId): ScheduledTask
    {
        $scheduler = new WorkerScheduler($this->fixture->schedules, new UnlocatedSunCalendar(), new NullLogger(), ResourceScope::forApp(new AppId($appId)));

        return $scheduler->runEvery(Duration::seconds(10), function (): void {
            ++$this->runs;
        });
    }
}
