<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Control\Request;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Exception\IdentifierError;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Broker\AppPauseRegistry;
use Stewart\Runtime\Broker\AppPauseService;
use Stewart\Runtime\Broker\WorkerSlotRegistry;
use Stewart\Runtime\Control\Protocol\Frame\CommandResult;
use Stewart\Runtime\Control\Protocol\Frame\PauseAppRequest;
use Stewart\Runtime\Control\Protocol\Frame\ResumeAppRequest;
use Stewart\Runtime\Control\Request\AppPauseChangeHandler;
use Stewart\Runtime\Control\Request\PauseAppRequestHandler;
use Stewart\Runtime\Control\Request\ResumeAppRequestHandler;
use Stewart\Runtime\Exception\AppError;
use Stewart\Runtime\Lifecycle\AppPauseSource;
use Stewart\Runtime\Tests\Fixtures\Apps\Demo;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(PauseAppRequestHandler::class)]
#[CoversClass(ResumeAppRequestHandler::class)]
#[CoversClass(AppPauseChangeHandler::class)]
final class AppPauseRequestHandlersTest extends TestCase
{
    use AssertsReason;

    private AppPauseRegistry $registry;

    private PauseAppRequestHandler $pause;

    private ResumeAppRequestHandler $resume;

    protected function setUp(): void
    {
        $clock = new VirtualClock();
        $apps = AppDefinitionCollection::keyedByAppId([new AppDefinition(new AppId('demo'), Demo::class)]);
        $this->registry = new AppPauseRegistry($apps, $clock);
        $service = new AppPauseService(
            new AppCatalog($apps, AppIdCollection::fromIds([new AppId('demo')]), AppIdCollection::fromIds([])),
            $this->registry,
            new WorkerSlotRegistry(),
            $clock,
            new RecordingLogger(),
        );
        $this->pause = new PauseAppRequestHandler($service);
        $this->resume = new ResumeAppRequestHandler($service);
    }

    public function testPauseIsReportedAsChangedOnce(): void
    {
        self::assertEquals(new CommandResult(true, 'App demo paused.'), $this->pause->answerRequest(new PauseAppRequest('demo')));
        self::assertEquals(new CommandResult(false, 'App demo was already paused.'), $this->pause->answerRequest(new PauseAppRequest('demo')));
        self::assertSame(AppPauseSource::Control, $this->registry->findPause(new AppId('demo'))?->source);
    }

    public function testResumeIsReportedAsChangedOnce(): void
    {
        $this->pause->answerRequest(new PauseAppRequest('demo'));

        self::assertEquals(new CommandResult(true, 'App demo resumed.'), $this->resume->answerRequest(new ResumeAppRequest('demo')));
        self::assertEquals(new CommandResult(false, 'App demo was not paused.'), $this->resume->answerRequest(new ResumeAppRequest('demo')));
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
