<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Scope;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Scope\ScopeLifecycle;

#[CoversClass(ScopeLifecycle::class)]
final class ScopeLifecycleTest extends TestCase
{
    private ScopeLifecycle $scopes;

    protected function setUp(): void
    {
        $this->scopes = new ScopeLifecycle();
    }

    public function testActivatedScopeIsLive(): void
    {
        self::assertFalse($this->scopes->isLive(self::createScope('demo')));

        $this->scopes->activateScope(self::createScope('demo'));

        self::assertTrue($this->scopes->isLive(self::createScope('demo')));
        self::assertFalse($this->scopes->isLive(self::createScope('other')));
        self::assertFalse($this->scopes->isClosed(self::createScope('demo')));
    }

    public function testReleasedScopeIsClosedAndNotLive(): void
    {
        $this->scopes->activateScope(self::createScope('demo'));

        $this->scopes->releaseScope(self::createScope('demo'));

        self::assertFalse($this->scopes->isLive(self::createScope('demo')));
        self::assertTrue($this->scopes->isClosed(self::createScope('demo')));
        self::assertFalse($this->scopes->isClosed(self::createScope('other')));
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

        self::assertFalse($this->scopes->isLive(self::createScope('demo')));
        self::assertTrue($this->scopes->isClosed(self::createScope('demo')));
        self::assertTrue($this->scopes->isClosed(ResourceScope::shared()));
    }

    public function testActivationAfterStopStaysDormant(): void
    {
        $this->scopes->stopAll();

        $this->scopes->activateScope(self::createScope('demo'));

        self::assertFalse($this->scopes->isLive(self::createScope('demo')));
    }

    public function testPausedScopeIsPausedUntilResumed(): void
    {
        $this->scopes->pauseScope(self::createScope('demo'));

        self::assertTrue($this->scopes->isPaused(self::createScope('demo')));
        self::assertFalse($this->scopes->isPaused(self::createScope('other')));

        $this->scopes->resumeScope(self::createScope('demo'));

        self::assertFalse($this->scopes->isPaused(self::createScope('demo')));
    }

    public function testSharedScopeCannotBePaused(): void
    {
        $this->expectException(LogicException::class);

        $this->scopes->pauseScope(ResourceScope::shared());
    }

    private static function createScope(string $appId): ResourceScope
    {
        return ResourceScope::forApp(new AppId($appId));
    }
}
