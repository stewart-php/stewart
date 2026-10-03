<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Stream;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Stewart\Contracts\Stream\SubscriptionScope;
use Stewart\Contracts\Subscription;

#[CoversClass(SubscriptionScope::class)]
final class SubscriptionScopeTest extends TestCase
{
    private SubscriptionScope $scope;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->scope = new SubscriptionScope();
        $this->log = [];
    }

    public function testEmissionRunsInlineUntilQueueIsProvided(): void
    {
        $this->scope->emit($this->record('inline'));

        self::assertSame(['inline'], $this->log, 'Without a runtime the scope is still usable, which is what lets operators be tested without a dispatcher.');
    }

    public function testRuntimeSinkTakesOverDelivery(): void
    {
        $deferred = [];
        $this->scope->deliverVia(static function (Closure $emission) use (&$deferred): void {
            $deferred[] = $emission;
        });

        $this->scope->emit($this->record('queued'));

        self::assertSame([], $this->log, 'The sink decides when the emission runs.');
        self::assertCount(1, $deferred);

        $deferred[0]();

        self::assertSame(['queued'], $this->log);
    }

    public function testClosedScopeEmitsNothing(): void
    {
        $this->scope->close();

        $this->scope->emit($this->record('late'));

        self::assertSame([], $this->log, 'A timer that fires after teardown must not reach a disposed app.');
    }

    public function testTeardownsRunOnceInRegistrationOrder(): void
    {
        $this->scope->onTeardown($this->record('first'));
        $this->scope->onTeardown($this->record('second'));

        $this->scope->close();
        $this->scope->close();

        self::assertSame(['first', 'second'], $this->log);
    }

    public function testTeardownRegisteredAfterCloseRunsImmediately(): void
    {
        $this->scope->close();

        $this->scope->onTeardown($this->record('late'));

        self::assertSame(['late'], $this->log, 'Otherwise a notifier subscribed during a racing teardown would leak.');
    }

    public function testEveryTeardownRunsEvenWhenAnEarlierOneThrows(): void
    {
        $this->scope->onTeardown(static fn() => throw new RuntimeException('first blew up'));
        $this->scope->onTeardown($this->record('still ran'));

        try {
            $this->scope->close();
            self::fail('The first failure should surface.');
        } catch (RuntimeException $e) {
            self::assertSame('first blew up', $e->getMessage());
        }

        self::assertSame(['still ran'], $this->log, 'One broken operator must not leak the timers of the others.');
    }

    public function testClosingCancelsTheSubscription(): void
    {
        $subscription = $this->createSubscription();
        $this->scope->attachedTo($subscription);

        $this->scope->close();

        self::assertFalse($subscription->isActive());
    }

    public function testSubscriptionAfterEarlyCloseIsCancelledAtOnce(): void
    {
        $this->scope->close();

        $subscription = $this->createSubscription();
        $this->scope->attachedTo($subscription);

        self::assertFalse($subscription->isActive());
    }

    public function testTeardownsRunBeforeTheSubscriptionIsCancelled(): void
    {
        // Cancelling reaches the transport and can suspend, so timers must already be dead.
        $subscription = $this->createSubscription('unsubscribe');
        $this->scope->onTeardown($this->record('teardown'));
        $this->scope->attachedTo($subscription);

        $this->scope->close();

        self::assertSame(['teardown', 'unsubscribe'], $this->log);
    }

    public function testDownstreamCloseWithNothingOutstanding(): void
    {
        $this->scope->closeWhenDownstreamSettles(1);

        self::assertFalse($this->scope->isActive());
    }

    public function testDownstreamCloseWaitsForOutstanding(): void
    {
        $pending = new stdClass();
        $this->scope->trackOutstanding($pending, 0);

        $this->scope->closeWhenDownstreamSettles(1);

        self::assertTrue($this->scope->isActive(), 'A window after take() still holds an accepted event.');

        $this->scope->untrackOutstanding($pending);

        self::assertFalse($this->scope->isActive());
    }

    public function testDownstreamCloseIgnoresUpstreamStages(): void
    {
        $this->scope->trackOutstanding(new stdClass(), 2);

        $this->scope->closeWhenDownstreamSettles(1);

        self::assertFalse($this->scope->isActive(), 'Whatever sits before take() can no longer get through it.');
    }

    public function testTrackingTwiceNeedsOneUntrack(): void
    {
        $pending = new stdClass();
        $this->scope->trackOutstanding($pending, 0);
        $this->scope->trackOutstanding($pending, 0);
        $this->scope->closeWhenDownstreamSettles(1);

        $this->scope->untrackOutstanding($pending);

        self::assertFalse($this->scope->isActive());
    }

    public function testSettleDuringEmissionClosesAfterIt(): void
    {
        $pending = new stdClass();
        $this->scope->trackOutstanding($pending, 0);
        $this->scope->closeWhenDownstreamSettles(1);

        $this->scope->emit(function () use ($pending): void {
            $this->scope->untrackOutstanding($pending);
            $this->log[] = $this->scope->isActive() ? 'active' : 'closed';
        });

        self::assertSame(['active'], $this->log, 'The emission that settles the scope still runs to its end.');
        self::assertFalse($this->scope->isActive());
    }

    public function testThrowingEmissionStillClosesSettledScope(): void
    {
        $pending = new stdClass();
        $this->scope->trackOutstanding($pending, 0);
        $this->scope->closeWhenDownstreamSettles(1);

        try {
            $this->scope->emit(function () use ($pending): void {
                $this->scope->untrackOutstanding($pending);

                throw new RuntimeException('handler blew up');
            });
            self::fail('The emission failure must reach the caller.');
        } catch (RuntimeException $e) {
            self::assertSame('handler blew up', $e->getMessage());
        }

        self::assertFalse($this->scope->isActive());
    }

    public function testQueuedEmissionIsSkippedOnceClosed(): void
    {
        $deferred = [];
        $this->scope->deliverVia(static function (Closure $emission) use (&$deferred): void {
            $deferred[] = $emission;
        });
        $this->scope->emit($this->record('queued'));

        $this->scope->close();
        $deferred[0]();

        self::assertSame([], $this->log);
    }

    public function testCloseIgnoresOutstandingEmissions(): void
    {
        $this->scope->trackOutstanding(new stdClass(), 0);

        $this->scope->close();

        self::assertFalse($this->scope->isActive(), 'Unsubscribing never waits for held events.');
    }

    public function testIsActiveTracksClosure(): void
    {
        self::assertTrue($this->scope->isActive());

        $this->scope->close();

        self::assertFalse($this->scope->isActive());
    }

    private function createSubscription(?string $label = null): Subscription
    {
        return new class ($label === null ? null : $this->record($label)) implements Subscription {
            private bool $active = true;

            /** @param (Closure(): void)|null $onUnsubscribe */
            public function __construct(private readonly ?Closure $onUnsubscribe) {}

            public function unsubscribe(): void
            {
                $this->active = false;

                if ($this->onUnsubscribe !== null) {
                    ($this->onUnsubscribe)();
                }
            }

            public function isActive(): bool
            {
                return $this->active;
            }

            public function getId(): string
            {
                return 'test:0';
            }
        };
    }

    /** @return Closure(): void */
    private function record(string $label): Closure
    {
        return function () use ($label): void {
            $this->log[] = $label;
        };
    }
}
