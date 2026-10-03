<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Stewart\Runtime\Broker\LoopErrorLogger;
use Stewart\Testing\Logging\RecordingLogger;
use Stewart\Testing\Time\EventLoopTicks;
use Throwable;

#[CoversClass(LoopErrorLogger::class)]
final class LoopErrorLoggerTest extends TestCase
{
    private RecordingLogger $logger;

    private LoopErrorLogger $loopErrors;

    /** @var (Closure(Throwable): void)|null */
    private ?Closure $originalHandler;

    protected function setUp(): void
    {
        $this->originalHandler = EventLoop::getErrorHandler();
        $this->logger = new RecordingLogger();
        $this->loopErrors = new LoopErrorLogger($this->logger);
    }

    protected function tearDown(): void
    {
        EventLoop::setErrorHandler($this->originalHandler);
    }

    public function testThrowingCallbackIsLoggedAndLoopGoesOn(): void
    {
        $failure = new RuntimeException('timer blew up');
        $this->loopErrors->install();

        EventLoop::queue(static fn() => throw $failure);
        EventLoopTicks::settle();

        self::assertSame(['Unhandled error in the event loop'], $this->logger->listMessagesAt('critical'));
        self::assertSame($failure, $this->logger->records->getFirst()?->context['exception']);
    }

    public function testRestoringPutsThePreviousHandlerBack(): void
    {
        $previous = static function (Throwable $error): void {};
        EventLoop::setErrorHandler($previous);

        $this->loopErrors->install();
        $this->loopErrors->install();
        $this->loopErrors->restorePrevious();

        self::assertSame($previous, EventLoop::getErrorHandler());
    }
}
