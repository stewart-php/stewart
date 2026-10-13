<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\IdentifierError;
use Stewart\Runtime\Admin\RuntimeAdmin;
use Stewart\Runtime\App\AppPauseOverride;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Stewart\Runtime\Exception\AppError;
use Stewart\Runtime\Exception\WorkerError;
use Stewart\Runtime\Lifecycle\AppPauseOverridePersistence;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Runtime\Tests\Fixtures\Http\AdminApiFixture;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Store\InMemoryStoreBackend;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(RuntimeAdmin::class)]
final class RuntimeAdminTest extends TestCase
{
    use AssertsReason;

    private const string NOT_SAVED = 'Not saved: persistence.url is not set, so this lasts until the daemon restarts.';

    private AdminApiFixture $fixture;

    private RuntimeAdmin $admin;

    protected function setUp(): void
    {
        $this->useFixture(new AdminApiFixture());
    }

    protected function tearDown(): void
    {
        $this->fixture->stopEverything();
    }

    public function testPauseIsReportedAsChangedOnce(): void
    {
        self::assertEquals(new CommandResult(true, 'App demo paused.', self::NOT_SAVED), $this->pause('demo'));
        self::assertEquals(new CommandResult(false, 'App demo was already paused.', self::NOT_SAVED), $this->pause('demo'));
        self::assertSame(AppPauseSource::Control, $this->fixture->registry->findPause(new AppId('demo'))?->source);
    }

    public function testResumeIsReportedAsChangedOnce(): void
    {
        $this->pause('demo');

        self::assertEquals(new CommandResult(true, 'App demo resumed.', self::NOT_SAVED), $this->admin->resumeApp(new AppId('demo'), AppPauseSource::Http));
        self::assertEquals(new CommandResult(false, 'App demo was not paused.', self::NOT_SAVED), $this->admin->resumeApp(new AppId('demo'), AppPauseSource::Http));
    }

    public function testResetNamesStateItLeaves(): void
    {
        $this->pause('demo');
        $this->admin->resumeApp(new AppId('porch'), AppPauseSource::Control);

        self::assertEquals(new CommandResult(true, 'App demo pause override removed; it is running.', self::NOT_SAVED), $this->reset('demo'));
        self::assertEquals(new CommandResult(false, 'App demo had no pause override.', null), $this->reset('demo'));
        self::assertEquals(new CommandResult(true, 'App porch pause override removed; config keeps it paused.', self::NOT_SAVED), $this->reset('porch'));
        self::assertTrue($this->fixture->registry->isPaused(new AppId('porch')));
    }

    public function testResetOfAppNotLoadedRemovesStoredOverride(): void
    {
        $this->useFixture(new AdminApiFixture(new InMemoryStoreBackend(new VirtualClock())));
        $this->fixture->overrides->saveOverride(new AppPauseOverride(new AppId('retired'), true, $this->fixture->clock->getNow(), AppPauseSource::Control));

        self::assertEquals(new CommandResult(true, 'App retired pause override removed.', null), $this->reset('retired'));
    }

    public function testFailedStoreWriteWarnsEvenWithoutOverride(): void
    {
        $backend = new InMemoryStoreBackend(new VirtualClock());
        $this->useFixture(new AdminApiFixture($backend));
        $backend->simulateOutage('down');

        self::assertEquals(new CommandResult(true, 'App demo paused.', AppPauseOverridePersistence::Failed->findWarning()), $this->pause('demo'));
        self::assertEquals(new CommandResult(false, 'App porch had no pause override.', AppPauseOverridePersistence::Failed->findWarning()), $this->reset('porch'));
    }

    public function testUnknownAppIsRefused(): void
    {
        $this->assertThrowsReason(AppError::Unknown, fn() => $this->pause('ghost'));
        $this->assertThrowsReason(AppError::Unknown, fn() => $this->admin->requireAppStatus(new AppId('ghost')));
    }

    public function testMalformedAppIdIsRefused(): void
    {
        $this->assertThrowsReason(IdentifierError::AppIdInvalid, fn() => $this->pause('Not An Id'));
    }

    public function testRestartIsReportedAsChangedOnce(): void
    {
        $this->fixture->startWorker();

        self::assertEquals(new CommandResult(true, 'Worker 0 is restarting.', null), $this->admin->restartWorker('0'));
        self::assertEquals(new CommandResult(false, 'Worker 0 is already starting or stopping.', null), $this->admin->restartWorker('0'));
    }

    public function testWorkerOutsideThePoolIsUnknown(): void
    {
        $this->assertThrowsReason(WorkerError::Unknown, fn() => $this->admin->restartWorker('4'));
    }

    private function useFixture(AdminApiFixture $fixture): void
    {
        if (isset($this->fixture)) {
            $this->fixture->stopEverything();
        }

        $this->fixture = $fixture;
        $this->admin = $fixture->admin;
    }

    private function pause(string $appId): CommandResult
    {
        return $this->admin->pauseApp(new AppId($appId), AppPauseSource::Control);
    }

    private function reset(string $appId): CommandResult
    {
        return $this->admin->resetApp(new AppId($appId), AppPauseSource::Control);
    }
}
