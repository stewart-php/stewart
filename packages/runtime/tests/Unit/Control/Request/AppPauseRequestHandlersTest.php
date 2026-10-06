<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Control\Request;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Exception\IdentifierError;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppPauseOverrideCodec;
use Stewart\Runtime\Broker\AppPauseOverrideStore;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Broker\DaemonStartTime;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Stewart\Runtime\Control\Protocol\Frame\PauseAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\ResetAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\ResumeAppRequest;
use Stewart\Runtime\Control\Request\AppPauseChangeHandler;
use Stewart\Runtime\Control\Request\PauseAppRequestHandler;
use Stewart\Runtime\Control\Request\ResetAppRequestHandler;
use Stewart\Runtime\Control\Request\ResumeAppRequestHandler;
use Stewart\Runtime\Exception\AppError;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(PauseAppRequestHandler::class)]
#[CoversClass(ResumeAppRequestHandler::class)]
#[CoversClass(ResetAppRequestHandler::class)]
#[CoversClass(AppPauseChangeHandler::class)]
final class AppPauseRequestHandlersTest extends TestCase
{
    use AssertsReason;

    private const string NOT_SAVED = 'Not saved: persistence.url is not set, so this lasts until the daemon restarts.';

    private AppPauseRegistry $registry;

    private PauseAppRequestHandler $pause;

    private ResumeAppRequestHandler $resume;

    private ResetAppRequestHandler $reset;

    protected function setUp(): void
    {
        $clock = new VirtualClock();
        $apps = AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class), new AppDefinition(new AppId('porch'), Demo::class, startsPaused: true)]);
        $this->registry = new AppPauseRegistry($apps, new DaemonStartTime($clock));
        $service = new AppPauseService(
            new AppCatalog($apps, AppIdCollection::fromIds([new AppId('demo'), new AppId('porch')]), AppIdCollection::fromIds([])),
            $this->registry,
            new AppPauseOverrideStore(new AppPauseOverrideCodec(AppPauseOverrideCodec::createOverrideWireMapper()), new NullLogger()),
            new WorkerSlotRegistry(),
            $clock,
            new RecordingLogger(),
        );
        $this->pause = new PauseAppRequestHandler($service);
        $this->resume = new ResumeAppRequestHandler($service);
        $this->reset = new ResetAppRequestHandler($service);
    }

    public function testPauseIsReportedAsChangedOnce(): void
    {
        self::assertEquals(new CommandResult(true, 'App demo paused.', self::NOT_SAVED), $this->pause->answerRequest(new PauseAppRequest('demo')));
        self::assertEquals(new CommandResult(false, 'App demo was already paused.', self::NOT_SAVED), $this->pause->answerRequest(new PauseAppRequest('demo')));
        self::assertSame(AppPauseSource::Control, $this->registry->findPause(new AppId('demo'))?->source);
    }

    public function testResumeIsReportedAsChangedOnce(): void
    {
        $this->pause->answerRequest(new PauseAppRequest('demo'));

        self::assertEquals(new CommandResult(true, 'App demo resumed.', self::NOT_SAVED), $this->resume->answerRequest(new ResumeAppRequest('demo')));
        self::assertEquals(new CommandResult(false, 'App demo was not paused.', self::NOT_SAVED), $this->resume->answerRequest(new ResumeAppRequest('demo')));
    }

    public function testResetNamesStateItLeaves(): void
    {
        $this->pause->answerRequest(new PauseAppRequest('demo'));
        $this->resume->answerRequest(new ResumeAppRequest('porch'));

        self::assertEquals(new CommandResult(true, 'App demo pause override removed; it is running.', self::NOT_SAVED), $this->reset->answerRequest(new ResetAppRequest('demo')));
        self::assertEquals(new CommandResult(false, 'App demo had no pause override.', self::NOT_SAVED), $this->reset->answerRequest(new ResetAppRequest('demo')));
        self::assertEquals(new CommandResult(true, 'App porch pause override removed; config keeps it paused.', self::NOT_SAVED), $this->reset->answerRequest(new ResetAppRequest('porch')));
        self::assertTrue($this->registry->isPaused(new AppId('porch')));
    }

    public function testUnknownAppIsRefused(): void
    {
        $this->assertThrowsReason(AppError::Unknown, fn() => $this->pause->answerRequest(new PauseAppRequest('ghost')));
    }

    public function testMalformedAppIdIsRefused(): void
    {
        $this->assertThrowsReason(IdentifierError::AppIdInvalid, fn() => $this->resume->answerRequest(new ResumeAppRequest('Not An Id')));
    }
}
