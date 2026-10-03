<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Stewart\Runtime\Ipc\Message\AppFailed;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Tests\Fixtures\Ipc\NullTransport;
use Stewart\Runtime\Worker\AppActivityCounters;
use Stewart\Runtime\Worker\AppFailureReporter;
use Stewart\Runtime\Worker\HandlerFailureSampler;
use Stewart\Runtime\Worker\LoopErrorReporter;
use Stewart\Runtime\Worker\StderrFallback;
use Stewart\Testing\Time\EventLoopTicks;
use Throwable;

#[CoversClass(LoopErrorReporter::class)]
final class LoopErrorReporterTest extends TestCase
{
    private NullTransport $transport;

    private LoopErrorReporter $loopErrors;

    /** @var (Closure(Throwable): void)|null */
    private ?Closure $originalHandler;

    protected function setUp(): void
    {
        $this->originalHandler = EventLoop::getErrorHandler();
        $this->transport = new NullTransport();
        $this->loopErrors = new LoopErrorReporter(new AppFailureReporter($this->transport, new StderrFallback(new WorkerId(0)), new AppActivityCounters(), new HandlerFailureSampler()));
    }

    protected function tearDown(): void
    {
        EventLoop::setErrorHandler($this->originalHandler);
    }

    public function testLoopErrorIsReportedAsSharedFailure(): void
    {
        $this->loopErrors->install();

        EventLoop::queue(static fn() => throw new RuntimeException('callback blew up'));
        EventLoopTicks::settle();

        self::assertCount(1, $this->transport->sent);
        $failure = $this->transport->sent[0];
        self::assertInstanceOf(AppFailed::class, $failure);
        self::assertTrue($failure->scope->isShared());
        self::assertSame(AppFailurePhase::Handler, $failure->phase);
        self::assertSame('event loop', $failure->origin);
        self::assertSame('callback blew up', $failure->message);
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
