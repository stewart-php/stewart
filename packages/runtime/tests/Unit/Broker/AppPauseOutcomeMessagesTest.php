<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\App\AppPauseOutcome;
use Stewart\Runtime\App\AppPauseResetOutcome;
use Stewart\Runtime\Broker\AppPauseOutcomeMessages;
use Stewart\Runtime\Lifecycle\AppPauseOverridePersistence;
use Stewart\Runtime\Lifecycle\AppStateAfterReset;

#[CoversClass(AppPauseOutcomeMessages::class)]
final class AppPauseOutcomeMessagesTest extends TestCase
{
    private AppPauseOutcomeMessages $messages;

    private AppId $appId;

    protected function setUp(): void
    {
        $this->messages = new AppPauseOutcomeMessages();
        $this->appId = new AppId('lights');
    }

    public function testPauseDescribesChangeAndNoOp(): void
    {
        self::assertSame('App lights paused.', $this->messages->describePauseOutcome($this->appId, $this->createOutcome(true)));
        self::assertSame('App lights was already paused.', $this->messages->describePauseOutcome($this->appId, $this->createOutcome(false)));
    }

    public function testResumeDescribesChangeAndNoOp(): void
    {
        self::assertSame('App lights resumed.', $this->messages->describeResumeOutcome($this->appId, $this->createOutcome(true)));
        self::assertSame('App lights was not paused.', $this->messages->describeResumeOutcome($this->appId, $this->createOutcome(false)));
    }

    public function testChangeWarnsOnlyWhenNotStored(): void
    {
        self::assertNull($this->messages->findChangeWarning($this->createOutcome(true)));
        self::assertSame(
            AppPauseOverridePersistence::Failed->findWarning(),
            $this->messages->findChangeWarning(new AppPauseOutcome(false, AppPauseOverridePersistence::Failed)),
        );
    }

    public function testResetDescribesEveryStateAfter(): void
    {
        self::assertSame('App lights pause override removed; config keeps it paused.', $this->describeReset(true, AppStateAfterReset::PausedByConfig));
        self::assertSame('App lights pause override removed; it is running.', $this->describeReset(true, AppStateAfterReset::Running));
        self::assertSame('App lights pause override removed.', $this->describeReset(true, AppStateAfterReset::NotLoaded));
        self::assertSame('App lights had no pause override.', $this->describeReset(false, AppStateAfterReset::Running));
    }

    public function testResetWithoutOverrideOrStoreHasNoWarning(): void
    {
        $outcome = new AppPauseResetOutcome(false, AppStateAfterReset::Running, AppPauseOverridePersistence::NotConfigured);

        self::assertNull($this->messages->findResetWarning($outcome));
    }

    public function testRemovedOverrideWithoutStoreWarns(): void
    {
        $outcome = new AppPauseResetOutcome(true, AppStateAfterReset::Running, AppPauseOverridePersistence::NotConfigured);

        self::assertSame(AppPauseOverridePersistence::NotConfigured->findWarning(), $this->messages->findResetWarning($outcome));
    }

    public function testFailedStoreWriteWarnsEvenWithoutOverride(): void
    {
        $outcome = new AppPauseResetOutcome(false, AppStateAfterReset::Running, AppPauseOverridePersistence::Failed);

        self::assertSame(AppPauseOverridePersistence::Failed->findWarning(), $this->messages->findResetWarning($outcome));
    }

    private function createOutcome(bool $changed): AppPauseOutcome
    {
        return new AppPauseOutcome($changed, AppPauseOverridePersistence::Stored);
    }

    private function describeReset(bool $overrideRemoved, AppStateAfterReset $stateAfter): string
    {
        return $this->messages->describeResetOutcome($this->appId, new AppPauseResetOutcome($overrideRemoved, $stateAfter, AppPauseOverridePersistence::Stored));
    }
}
