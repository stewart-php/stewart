<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Stream;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\StateChangeStream;
use Stewart\Contracts\Stream\StartWithCurrentStateOperator;
use Stewart\Contracts\Stream\StateChanges;
use Stewart\Contracts\Tests\Fixtures\State\FixedStateReader;
use Stewart\Testing\Stream\PushSource;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(StartWithCurrentStateOperator::class)]
#[CoversClass(StateChange::class)]
final class StartWithCurrentStateTest extends TestCase
{
    private const string HALL = 'light.hall';

    private const string PORCH = 'light.porch';

    /** @var PushSource<StateChange> */
    private PushSource $source;

    private FixedStateReader $currentStates;

    private StateChanges $stream;

    /** @var list<string> */
    private array $received = [];

    protected function setUp(): void
    {
        $this->source = new PushSource();
        $this->currentStates = new FixedStateReader();
        $this->stream = new StateChanges($this->source, new ManualTimers(), $this->currentStates);
        $this->received = [];
    }

    public function testSeedsCurrentStatesBeforeLiveChanges(): void
    {
        $this->currentStates->recordState($this->createState(self::PORCH, 'off'));
        $this->currentStates->recordState($this->createState(self::HALL, 'on'));

        $this->listen($this->stream->startWithCurrentState());
        $this->push(self::HALL, 'on', 'off');

        self::assertSame(['light.hall=on', 'light.porch=off', 'light.hall=off'], $this->received);
    }

    public function testSeedIsMarkedInitial(): void
    {
        $this->currentStates->recordState($this->createState(self::HALL, 'on'));
        $seeds = [];

        $this->stream->startWithCurrentState()->subscribe(static function (StateChange $change) use (&$seeds): void {
            $seeds[] = $change;
        });

        self::assertCount(1, $seeds);
        self::assertTrue($seeds[0]->isInitial());
        self::assertFalse($seeds[0]->isNew());
        self::assertFalse($seeds[0]->hasStateChanged());
    }

    public function testSeedSkipsOperatorsBeforeIt(): void
    {
        $this->currentStates->recordState($this->createState(self::HALL, 'on'));

        $this->listen($this->stream->filter(static fn(): bool => false)->startWithCurrentState());
        $this->push(self::HALL, 'on', 'off');

        self::assertSame(['light.hall=on'], $this->received);
    }

    public function testSeedPassesOperatorsAfterIt(): void
    {
        $this->currentStates->recordState($this->createState(self::HALL, 'on'));
        $this->currentStates->recordState($this->createState(self::PORCH, 'off'));

        $this->listen($this->stream->startWithCurrentState()->filter(static fn(StateChange $change): bool => $change->to?->state === 'on'));

        self::assertSame(['light.hall=on'], $this->received);
    }

    public function testTransitionTreatsSeedAsNoChange(): void
    {
        $this->currentStates->recordState($this->createState(self::HALL, 'on'));

        $this->listen($this->stream->startWithCurrentState()->whenChangedTo('on'));

        self::assertSame([], $this->received);
    }

    public function testTakeOneStopsAfterSeed(): void
    {
        $this->currentStates->recordState($this->createState(self::HALL, 'on'));

        $this->listen($this->stream->startWithCurrentState()->take(1));
        $this->push(self::HALL, 'on', 'off');

        self::assertSame(['light.hall=on'], $this->received);
        self::assertSame(0, $this->source->countSubscribers());
    }

    public function testEmptySelectionEmitsNoSeed(): void
    {
        $this->listen($this->stream->startWithCurrentState());

        self::assertSame([], $this->received);
    }

    private function listen(StateChangeStream $stream): void
    {
        $stream->subscribe(function (StateChange $change): void {
            $this->received[] = $change->entityId->value . '=' . ($change->to === null ? '<removed>' : $change->to->state);
        });
    }

    private function push(string $entityId, string $from, string $to): void
    {
        $this->source->push(new StateChange(new EntityId($entityId), $this->createState($entityId, $from), $this->createState($entityId, $to)));
    }

    private function createState(string $entityId, string $state): EntityState
    {
        return new EntityState(new EntityId($entityId), $state);
    }
}
