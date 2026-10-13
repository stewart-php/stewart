<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Scope;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Scope\ScopeLifecycle;
use Stewart\Runtime\Scope\ScopeState;

#[CoversClass(ScopeLifecycle::class)]
#[CoversClass(ScopeState::class)]
final class ScopeLifecycleTest extends TestCase
{
    private ScopeLifecycle $scopes;

    protected function setUp(): void
    {
        $this->scopes = new ScopeLifecycle();
    }

    public function testActivatedScopeIsLive(): void
    {
        self::assertFalse($this->findState('demo')->isLive());

        $this->scopes->activateScope(self::createScope('demo'));

        self::assertTrue($this->findState('demo')->isLive());
        self::assertFalse($this->findState('other')->isLive());
        self::assertFalse($this->findState('demo')->isClosed());
    }

    public function testOneScopeSharesOneState(): void
    {
        $state = $this->findState('demo');

        $this->scopes->activateScope(self::createScope('demo'));

        self::assertSame($state, $this->findState('demo'));
        self::assertTrue($state->isLive());
    }

    public function testReleasedScopeIsClosedAndNotLive(): void
    {
        $this->scopes->activateScope(self::createScope('demo'));

        $this->scopes->releaseScope(self::createScope('demo'));

        self::assertFalse($this->findState('demo')->isLive());
        self::assertTrue($this->findState('demo')->isClosed());
        self::assertFalse($this->findState('other')->isClosed());
    }

    public function testReleasedScopeCannotBeActivatedAgain(): void
    {
        $this->scopes->releaseScope(self::createScope('demo'));

        $this->expectException(LogicException::class);

        $this->scopes->activateScope(self::createScope('demo'));
    }

    public function testStopClosesEveryScope(): void
    {
        $this->scopes->activateScope(self::createScope('demo'));

        $this->scopes->stopAll();

        self::assertFalse($this->findState('demo')->isLive());
        self::assertTrue($this->findState('demo')->isClosed());
        self::assertTrue($this->scopes->findOrCreateStateOf(ResourceScope::shared())->isClosed());
    }

    public function testActivationAfterStopStaysDormant(): void
    {
        $this->scopes->stopAll();

        $this->scopes->activateScope(self::createScope('demo'));

        self::assertFalse($this->findState('demo')->isLive());
    }

    public function testPausedScopeIsPausedUntilResumed(): void
    {
        $this->scopes->pauseScope(self::createScope('demo'));

        self::assertTrue($this->findState('demo')->isPaused());
        self::assertFalse($this->findState('other')->isPaused());

        $this->scopes->resumeScope(self::createScope('demo'));

        self::assertFalse($this->findState('demo')->isPaused());
    }

    public function testScopePausedBeforeItGoesLiveStaysPaused(): void
    {
        $this->scopes->pauseScope(self::createScope('demo'));

        $this->scopes->activateScope(self::createScope('demo'));

        self::assertTrue($this->findState('demo')->isLive());
        self::assertTrue($this->findState('demo')->isPaused());
    }

    public function testSharedScopeCannotBePaused(): void
    {
        $this->expectException(LogicException::class);

        $this->scopes->pauseScope(ResourceScope::shared());
    }

    private function findState(string $appId): ScopeState
    {
        return $this->scopes->findOrCreateStateOf(self::createScope($appId));
    }

    private static function createScope(string $appId): ResourceScope
    {
        return ResourceScope::forApp(new AppId($appId));
    }
}
