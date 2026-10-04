<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Stream;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\StateError;
use Stewart\Contracts\Exception\StateException;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\StateChangeStream;
use Stewart\Contracts\StateTransitionStream;
use Stewart\Contracts\Stream\StateChanges;
use Stewart\Contracts\Stream\StateTransitions;
use Stewart\Contracts\Stream\WhenChangedToOperator;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Stream\PushSource;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(StateTransitions::class)]
#[CoversClass(WhenChangedToOperator::class)]
#[CoversClass(StateException::class)]
final class StateTransitionsTest extends TestCase
{
    use AssertsReason;

    private const string ENTITY = 'light.hall';

    private ManualTimers $timers;

    /** @var PushSource<StateChange> */
    private PushSource $source;

    private StateChanges $stream;

    /** @var list<string> */
    private array $received = [];

    protected function setUp(): void
    {
        $this->timers = new ManualTimers();
        $this->source = new PushSource();
        $this->stream = new StateChanges($this->source, $this->timers);
        $this->received = [];
    }

    public function testFromPassesOnlyListedPreviousState(): void
    {
        $this->listen($this->stream->whenChangedTo('on')->from('off'));

        $this->push('idle', 'on');
        $this->push('on', 'off');
        $this->push('off', 'on');

        self::assertSame(['off>on'], $this->received);
    }

    public function testFromAcceptsSeveralPreviousStates(): void
    {
        $this->listen($this->stream->whenChangedTo('on')->from('off', 'idle'));

        $this->push('idle', 'on');
        $this->push('on', 'standby');
        $this->push('standby', 'on');
        $this->push('on', 'off');
        $this->push('off', 'on');

        self::assertSame(['idle>on', 'off>on'], $this->received);
    }

    public function testFromAcceptsSpreadList(): void
    {
        $previousStates = ['off', 'idle'];
        $this->listen($this->stream->whenChangedTo('on')->from(...$previousStates));

        $this->push('idle', 'on');

        self::assertSame(['idle>on'], $this->received);
    }

    public function testFromAnyStatePassesUnavailablePrevious(): void
    {
        $this->listen($this->stream->whenChangedTo('on')->fromAnyState());

        $this->push(EntityState::UNAVAILABLE, 'on');
        $this->push('on', EntityState::UNKNOWN);
        $this->push(EntityState::UNKNOWN, 'on');
        $this->push(null, 'on');

        self::assertSame(['unavailable>on', 'unknown>on', '<new>>on'], $this->received);
    }

    public function testExplicitFromUnavailableOverridesDefault(): void
    {
        $this->listen($this->stream->whenChangedTo('on')->from(EntityState::UNAVAILABLE));

        $this->push(EntityState::UNAVAILABLE, 'on');

        self::assertSame(['unavailable>on'], $this->received);
    }

    public function testLastFromReplacesEarlierOne(): void
    {
        $this->listen($this->stream->whenChangedTo('on')->from('off')->from('idle'));

        $this->push('off', 'on');
        $this->push('on', 'idle');
        $this->push('idle', 'on');

        self::assertSame(['idle>on'], $this->received);
    }

    public function testFromWithWindowFiresAfterHold(): void
    {
        $this->listen($this->stream->whenChangedTo('on', for: Duration::seconds(3))->from('off'));

        $this->push('off', 'on');
        $this->timers->delay(Duration::seconds(3));

        self::assertSame(['off>on'], $this->received);
    }

    public function testRejectedPreviousDoesNotArmWindow(): void
    {
        $this->listen($this->stream->whenChangedTo('on', for: Duration::seconds(3))->from('off'));

        $this->push('idle', 'on');

        self::assertSame(0, $this->timers->countPendingTimers());
    }

    public function testFromKeepsWindowDisarmOnDeparture(): void
    {
        $this->listen($this->stream->whenChangedTo('on', for: Duration::seconds(3))->from('off'));

        $this->push('off', 'on');
        $this->timers->delay(Duration::seconds(1));
        $this->push('on', 'idle');
        $this->timers->delay(Duration::seconds(5));

        self::assertSame([], $this->received);
    }

    public function testOperatorsAfterFromKeepTransitionStream(): void
    {
        $chained = $this->stream->whenChangedTo('on')->from('off')->take(1);

        self::assertInstanceOf(StateTransitionStream::class, $chained);

        $this->listen($chained);

        $this->push('off', 'on');
        $this->push('on', 'off');
        $this->push('off', 'on');

        self::assertSame(['off>on'], $this->received);
    }

    public function testFromAfterOtherOperatorThrows(): void
    {
        $extended = $this->stream->whenChangedTo('on')->take(1);

        $this->assertThrowsReason(StateError::TransitionAlreadyExtended, static fn() => $extended->from('off'));
    }

    public function testFromAnyStateAfterOtherOperatorThrows(): void
    {
        $extended = $this->stream->whenChangedTo('on')->distinctUntilChanged();

        $this->assertThrowsReason(StateError::TransitionAlreadyExtended, static fn() => $extended->fromAnyState());
    }

    public function testChainedWhenChangedToStartsFreshTransition(): void
    {
        $this->listen($this->stream->whenChangedTo('on')->take(2)->whenChangedTo('on')->from('off'));

        $this->push('idle', 'on');
        $this->push('off', 'on');

        self::assertSame(['off>on'], $this->received);
    }

    private function listen(StateChangeStream $stream): void
    {
        $stream->subscribe(function (StateChange $change): void {
            $this->received[] = ($change->from === null ? '<new>' : $change->from->state) . '>' . ($change->to === null ? '<removed>' : $change->to->state);
        });
    }

    private function push(?string $from, string $to): void
    {
        $entityId = new EntityId(self::ENTITY);

        $this->source->push(new StateChange(
            $entityId,
            $from === null ? null : new EntityState($entityId, $from),
            new EntityState($entityId, $to),
        ));
    }
}
