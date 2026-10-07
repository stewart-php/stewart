<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Dispatch;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\State\StateChange;
use Stewart\Runtime\State\CachedStateReader;
use Stewart\Runtime\Tests\Fixtures\Dispatch\QueuedStreamFixture;
use Stewart\Runtime\Worker\Context\DispatchStreams;

#[CoversClass(DispatchStreams::class)]
#[CoversClass(CachedStateReader::class)]
final class QueuedStartWithCurrentStateTest extends TestCase
{
    private QueuedStreamFixture $streams;

    /** @var list<string> */
    private array $received = [];

    protected function setUp(): void
    {
        $this->streams = new QueuedStreamFixture();
        $this->received = [];
    }

    public function testSeedArrivesThroughQueueBeforeLiveChange(): void
    {
        $this->streams->seedState('light.hall', 'on');
        $this->streams->seedState('switch.fan', 'on');

        $this->listen('light.*');
        $this->streams->dispatchStateChange('light.hall', 'on', 'off');

        self::assertSame([], $this->received);

        $this->streams->drainQueues();

        self::assertSame(['initial:light.hall=on', 'live:light.hall=off'], $this->received);
    }

    public function testPausedScopeDropsSeed(): void
    {
        $this->streams->seedState('light.hall', 'on');
        $this->streams->pauseScope();

        $this->listen('light.*');
        $this->streams->drainQueues();

        self::assertSame([], $this->received);
    }

    private function listen(string $selector): void
    {
        $this->streams->watchStateChanges($selector)->startWithCurrentState()->subscribe(function (StateChange $change): void {
            $this->received[] = $change->origin->value . ':' . $change->entityId->value . '=' . $change->to?->state;
        });
    }
}
