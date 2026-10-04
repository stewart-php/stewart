<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\ServiceCallError;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Runtime\Model\WorkerId;
use Stewart\Runtime\Worker\CorrelationIdSequence;
use Stewart\Runtime\Worker\PendingCall;
use Stewart\Runtime\Worker\PendingCalls;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Time\EventLoopTicks;

use function Amp\async;

#[CoversClass(PendingCalls::class)]
#[CoversClass(PendingCall::class)]
final class PendingCallsTest extends TestCase
{
    use AssertsReason;

    public function testFailAllFailsEveryLiveWaiter(): void
    {
        $pending = new PendingCalls(new CorrelationIdSequence(new WorkerId(0)));
        $calls = [$pending->open('light', 'turn_on'), $pending->open('switch', 'toggle')];
        $waiters = array_map(static fn(PendingCall $call) => async(static fn() => $call->getFuture()->await()), $calls);
        EventLoopTicks::settle();

        self::assertSame(2, $pending->failAll('the broker is gone'));

        foreach ($waiters as $waiter) {
            $this->assertThrowsReason(ServiceCallError::Unreachable, static fn() => $waiter->await());
        }
    }

    public function testAnswerAfterFailAllIsIgnored(): void
    {
        $pending = new PendingCalls(new CorrelationIdSequence(new WorkerId(0)));
        $call = $pending->open('light', 'turn_on');
        $pending->failAll('the broker is gone');

        $pending->resolve($call->correlationId, new ServiceResponse('light', 'turn_on'));

        self::assertSame(0, $pending->failAll('again'));
        $this->assertThrowsReason(ServiceCallError::Unreachable, static fn() => $call->getFuture()->await());
    }
}
