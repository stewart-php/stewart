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
use Stewart\Contracts\Stream\CrossingLatches;
use Stewart\Contracts\Stream\StateChanges;
use Stewart\Contracts\Stream\ThresholdCrossingOperator;
use Stewart\Contracts\Stream\ThresholdDirection;
use Stewart\Contracts\Tests\Fixtures\State\FixedStateReader;
use Stewart\Contracts\Time\Duration;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Stream\PushSource;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(ThresholdCrossingOperator::class)]
#[CoversClass(ThresholdDirection::class)]
#[CoversClass(CrossingLatches::class)]
#[CoversClass(StateException::class)]
final class ThresholdCrossingTest extends TestCase
{
    use AssertsReason;

    private const string ENTITY = 'sensor.living_room_temperature';

    private const string OTHER = 'sensor.bedroom_temperature';

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
        $this->stream = new StateChanges($this->source, $this->timers, new FixedStateReader());
        $this->received = [];
    }

    public function testWhenAboveFiresOnUpwardCrossing(): void
    {
        $this->listen($this->stream->whenAbove(25.0));

        $this->pushValues('20', '24', '25', '26');

        self::assertSame(['26'], $this->received);
    }

    public function testFirstValueWithoutPreviousOnlyPrimes(): void
    {
        $this->listen($this->stream->whenAbove(25.0));

        $this->push(null, '26');
        $this->push(EntityState::UNAVAILABLE, '27');
        $this->push('27', '28');

        self::assertSame([], $this->received);
    }

    public function testFiresOncePerCrossing(): void
    {
        $this->listen($this->stream->whenAbove(25.0));

        $this->pushValues('20', '26', '27', '24', '26');

        self::assertSame(['26', '26'], $this->received);
    }

    public function testHysteresisDelaysRearming(): void
    {
        $this->listen($this->stream->whenAbove(25.0, hysteresis: 1.0));

        $this->pushValues('20', '26', '24.5', '26', '24', '26');

        self::assertSame(['26', '26'], $this->received);
    }

    public function testNonNumericValueForgetsEntity(): void
    {
        $this->listen($this->stream->whenAbove(25.0));

        $this->pushValues('20', EntityState::UNAVAILABLE, '26', '20', '27');

        self::assertSame(['27'], $this->received);
    }

    public function testReadsAttributeValue(): void
    {
        $this->listen($this->stream->whenAbove(25.0, attribute: 'current_temperature'));

        $this->source->push(new StateChange(
            new EntityId(self::ENTITY),
            $this->createState('heat', ['current_temperature' => 24.0]),
            $this->createState('heat', ['current_temperature' => 25.5]),
        ));

        self::assertSame(['heat'], $this->received);
    }

    public function testForWindowFiresAfterHolding(): void
    {
        $this->listen($this->stream->whenAbove(25.0, for: Duration::minutes(5)));

        $this->pushValues('20', '26');
        $this->timers->delay(Duration::minutes(4));

        self::assertSame([], $this->received);

        $this->push('26', '27');
        $this->timers->delay(Duration::minutes(1));

        self::assertSame(['26'], $this->received);
    }

    public function testDropToThresholdCancelsPending(): void
    {
        $this->listen($this->stream->whenAbove(25.0, for: Duration::minutes(5)));

        $this->pushValues('20', '26', '25');
        $this->timers->delay(Duration::minutes(10));

        self::assertSame([], $this->received);
    }

    public function testNonNumericCancelsPending(): void
    {
        $this->listen($this->stream->whenAbove(25.0, for: Duration::minutes(5)));

        $this->pushValues('20', '26', EntityState::UNAVAILABLE);
        $this->timers->delay(Duration::minutes(10));

        self::assertSame([], $this->received);
    }

    public function testLatchSetOnlyWhenPendingDelivered(): void
    {
        $this->listen($this->stream->whenAbove(25.0, for: Duration::minutes(5)));

        $this->pushValues('20', '26', '24', '26');
        $this->timers->delay(Duration::minutes(5));
        $this->push('26', '27');
        $this->timers->delay(Duration::minutes(10));

        self::assertSame(['26'], $this->received);
    }

    public function testWhenBelowFiresOnDownwardCrossing(): void
    {
        $this->listen($this->stream->whenBelow(18.0, hysteresis: 0.5));

        $this->pushValues('20', '17.5', '18.2', '17', '18.5', '17');

        self::assertSame(['17.5', '17'], $this->received);
    }

    public function testCrossingsTrackedPerEntity(): void
    {
        $this->stream->whenAbove(25.0)->subscribe(function (StateChange $change): void {
            $this->received[] = $change->entityId->value;
        });

        $this->push('20', '26');
        $this->pushFor(self::OTHER, '20', '26');
        $this->push('26', '27');

        self::assertSame([self::ENTITY, self::OTHER], $this->received);
    }

    public function testNegativeHysteresisThrows(): void
    {
        $this->assertThrowsReason(StateError::ThresholdHysteresisNegative, fn() => $this->stream->whenAbove(25.0, hysteresis: -0.5));
        $this->assertThrowsReason(StateError::ThresholdHysteresisNegative, fn() => $this->stream->whenBelow(25.0, hysteresis: \NAN));
    }

    private function listen(StateChangeStream $stream): void
    {
        $stream->subscribe(function (StateChange $change): void {
            $this->received[] = $change->to === null ? '<removed>' : $change->to->state;
        });
    }

    private function pushValues(string ...$values): void
    {
        for ($index = 1, $count = \count($values); $index < $count; ++$index) {
            $this->push($values[$index - 1], $values[$index]);
        }
    }

    private function push(?string $from, string $to): void
    {
        $this->pushFor(self::ENTITY, $from, $to);
    }

    private function pushFor(string $entityId, ?string $from, string $to): void
    {
        $this->source->push(new StateChange(
            new EntityId($entityId),
            $from === null ? null : $this->createState($from, entityId: $entityId),
            $this->createState($to, entityId: $entityId),
        ));
    }

    /** @param array<string, mixed> $attributes */
    private function createState(string $value, array $attributes = [], string $entityId = self::ENTITY): EntityState
    {
        return new EntityState(new EntityId($entityId), $value, $attributes);
    }
}
