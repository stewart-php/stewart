<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use Amp\DeferredCancellation;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stewart\Client\Exception\HaClientException;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Broker\Reconnector;
use Stewart\Runtime\Config\BackoffPolicy;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(Reconnector::class)]
final class ReconnectorTest extends TestCase
{
    private ManualTimers $timers;

    /** @var list<float> */
    private array $attemptedAt = [];

    private DeferredCancellation $stop;

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->attemptedAt = [];
        $this->stop = new DeferredCancellation();
    }

    public function testItKeepsTryingUntilTheAttemptSucceeds(): void
    {
        $this->createReconnector()->retryUntilConnected($this->createAttemptFailingTimes(times: 2), $this->stop->getCancellation());

        self::assertSame([0.0, 1.0, 3.0], $this->attemptedAt, 'The first try is immediate; the delay then doubles.');
    }

    public function testDelayStopsAtTheCap(): void
    {
        $this->createReconnector()->retryUntilConnected($this->createAttemptFailingTimes(times: 5), $this->stop->getCancellation());

        self::assertSame([0.0, 1.0, 3.0, 7.0, 11.0, 15.0], $this->attemptedAt);
    }

    public function testFatalFailureEscapesImmediately(): void
    {
        try {
            $this->createReconnector()->retryUntilConnected(
                function (): void {
                    $this->attemptedAt[] = $this->readElapsedSeconds();

                    throw HaClientException::tokenRejected('token rejected');
                },
                $this->stop->getCancellation(),
            );

            self::fail('A rejected token must not be retried.');
        } catch (HaClientException) {
            self::assertSame([0.0], $this->attemptedAt);
        }
    }

    public function testItStopsWhenTheCallerHasGivenUp(): void
    {
        $this->createReconnector()->retryUntilConnected(
            function (): void {
                $this->attemptedAt[] = $this->readElapsedSeconds();
                $this->stop->cancel();

                throw HaClientException::connectionDropped('refused');
            },
            $this->stop->getCancellation(),
        );

        self::assertSame([0.0], $this->attemptedAt, 'No retry is scheduled once the broker is stopping.');
    }

    public function testNoAttemptAfterCancelDuringWait(): void
    {
        $this->timers->startTimer(Duration::milliseconds(500), function (): void {
            $this->stop->cancel();
        });

        $this->createReconnector()->retryUntilConnected($this->createAttemptFailingTimes(times: 10), $this->stop->getCancellation());

        self::assertSame([0.0], $this->attemptedAt);
        self::assertSame(500_000, $this->timers->clock->getMonotonicTime()->toMicroseconds(), 'Stopping cuts the wait short.');
    }

    public function testItDoesNotWaitWhenTheFirstAttemptWorks(): void
    {
        $this->createReconnector()->retryUntilConnected($this->createAttemptFailingTimes(times: 0), $this->stop->getCancellation());

        self::assertSame(0, $this->timers->clock->getMonotonicTime()->toMicroseconds());
    }

    /** @return Closure(): void */
    private function createAttemptFailingTimes(int $times): Closure
    {
        return function () use ($times): void {
            $this->attemptedAt[] = $this->readElapsedSeconds();

            if (\count($this->attemptedAt) <= $times) {
                throw HaClientException::connectionDropped('refused');
            }
        };
    }

    private function readElapsedSeconds(): float
    {
        return $this->timers->clock->getMonotonicTime()->toMicroseconds() / 1_000_000;
    }

    private function createReconnector(): Reconnector
    {
        return new Reconnector(
            reconnectBackoff: new BackoffPolicy(Duration::seconds(1), Duration::seconds(4)),
            logger: new NullLogger(),
            deadlines: $this->timers,
        );
    }
}
