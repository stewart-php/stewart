<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit\Connection;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;
use Stewart\Client\Connection\MessageCorrelator;
use Stewart\Testing\Time\EventLoopTicks;
use Throwable;

#[CoversClass(MessageCorrelator::class)]
final class MessageCorrelatorTest extends TestCase
{
    /** @var (Closure(Throwable): void)|null */
    private ?Closure $originalLoopErrorHandler;

    protected function setUp(): void
    {
        $this->originalLoopErrorHandler = EventLoop::getErrorHandler();
    }

    protected function tearDown(): void
    {
        EventLoop::setErrorHandler($this->originalLoopErrorHandler);
    }

    public function testUnawaitedFailureStaysOutOfTheLoop(): void
    {
        $loopErrors = [];
        EventLoop::setErrorHandler(static function (Throwable $error) use (&$loopErrors): void {
            $loopErrors[] = $error;
        });

        $correlator = new MessageCorrelator();
        $correlator->expect($correlator->nextId());

        self::assertSame(1, $correlator->failAll(new RuntimeException('connection closed')));

        EventLoopTicks::settle();
        self::assertSame([], $loopErrors);
    }

    public function testAwaitedFailureStillThrows(): void
    {
        $correlator = new MessageCorrelator();
        $future = $correlator->expect($correlator->nextId());
        $failure = new RuntimeException('connection closed');

        $correlator->failAll($failure);

        $this->expectExceptionObject($failure);
        $future->await();
    }
}
