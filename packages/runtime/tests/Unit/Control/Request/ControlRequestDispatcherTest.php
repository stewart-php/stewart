<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Control\Request;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Stewart\Runtime\Control\Protocol\Frame\Hello;
use Stewart\Runtime\Control\Protocol\Frame\PauseAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\ReloadRequest;
use Stewart\Runtime\Control\Protocol\Frame\ResetAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\RestartWorkerRequest;
use Stewart\Runtime\Control\Protocol\Frame\ResumeAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotFrame;
use Stewart\Runtime\Control\Protocol\Frame\SnapshotRequest;
use Stewart\Runtime\Control\Request\ControlRequestDispatcher;
use Stewart\Runtime\Exception\ControlError;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Runtime\Tests\Fixtures\Control\StubSnapshotSource;
use Stewart\Runtime\Tests\Fixtures\Http\AdminApiFixture;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(ControlRequestDispatcher::class)]
final class ControlRequestDispatcherTest extends TestCase
{
    use AssertsReason;

    private AdminApiFixture $admin;

    private StubSnapshotSource $snapshots;

    private ControlRequestDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->admin = new AdminApiFixture();
        $this->snapshots = new StubSnapshotSource();
        $this->dispatcher = new ControlRequestDispatcher($this->snapshots, $this->admin->admin);
    }

    protected function tearDown(): void
    {
        $this->admin->stopEverything();
    }

    public function testSnapshotRequestIsAnsweredWithSnapshot(): void
    {
        self::assertInstanceOf(SnapshotFrame::class, $this->dispatcher->answerRequest(new SnapshotRequest()));
        self::assertSame(1, $this->snapshots->taken);
    }

    public function testAppCommandsChangePauseAsControl(): void
    {
        self::assertTrue($this->answerCommand(new PauseAppRequest('demo'))->changed);
        self::assertSame(AppPauseSource::Control, $this->admin->registry->findPause(new AppId('demo'))?->source);
        self::assertTrue($this->answerCommand(new ResumeAppRequest('demo'))->changed);
        self::assertTrue($this->answerCommand(new ResetAppRequest('demo'))->changed);
    }

    public function testWorkerRestartIsAnswered(): void
    {
        $this->admin->startWorker();

        self::assertTrue($this->answerCommand(new RestartWorkerRequest('0'))->changed);
    }

    public function testReloadIsPassedToTheRuntime(): void
    {
        $answer = $this->dispatcher->answerRequest(new ReloadRequest());

        self::assertInstanceOf(CommandResult::class, $answer);
        self::assertFalse($answer->changed);
        self::assertSame('Stewart is not running its apps yet, so there is nothing to reload.', $answer->message);
    }

    public function testOtherFrameIsUnexpected(): void
    {
        $this->assertThrowsReason(ControlError::RequestUnexpected, fn() => $this->dispatcher->answerRequest(new Hello('token', 'phpunit')));
    }

    private function answerCommand(PauseAppRequest|ResumeAppRequest|ResetAppRequest|RestartWorkerRequest $request): CommandResult
    {
        $answer = $this->dispatcher->answerRequest($request);
        self::assertInstanceOf(CommandResult::class, $answer);

        return $answer;
    }
}
