<?php

declare(strict_types=1);

namespace Stewart\Store\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\StoreError;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Contracts\Time\Duration;
use Stewart\Store\GuardedStoreBackend;
use Stewart\Store\StoreDsn;
use Stewart\Store\StoreHealth;
use Stewart\Store\StoreTiming;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Store\InMemoryStoreBackend;
use Stewart\Testing\Time\VirtualClock;

#[CoversClass(GuardedStoreBackend::class)]
#[CoversClass(StoreHealth::class)]
final class GuardedStoreBackendTest extends TestCase
{
    use AssertsReason;

    private VirtualClock $clock;

    private InMemoryStoreBackend $engine;

    private GuardedStoreBackend $guarded;

    protected function setUp(): void
    {
        $this->clock = new VirtualClock();
        $this->engine = new InMemoryStoreBackend($this->clock);
        $this->guarded = new GuardedStoreBackend(
            $this->engine,
            StoreDsn::parse('memory://local'),
            new StoreTiming(Duration::seconds(2), Duration::seconds(5)),
            $this->clock,
        );
    }

    public function testUnreachableEnginePausesFurtherCalls(): void
    {
        $this->failOnce();
        $this->engine->endOutage();

        $this->assertThrowsReason(StoreError::RecentlyFailed, fn() => $this->guarded->read('mode'));
    }

    public function testPauseEndsAfterRecoveryInterval(): void
    {
        $this->engine->write('mode', '"away"', null);
        $this->failOnce();
        $this->engine->endOutage();

        $this->moveTimePastRecoveryInterval();

        self::assertSame('"away"', $this->guarded->read('mode'));
        self::assertTrue($this->guarded->getHealth()->available);
    }

    public function testHealthStaysDownAfterPauseUntilStoreAnswers(): void
    {
        $this->failOnce();
        $this->engine->endOutage();
        $this->moveTimePastRecoveryInterval();

        self::assertFalse($this->guarded->getHealth()->available);

        $this->guarded->read('mode');

        self::assertTrue($this->guarded->getHealth()->available);
    }

    public function testRefusalAfterPauseRestoresHealth(): void
    {
        $this->failOnce();
        $this->engine->endOutage();
        $this->engine->write('mode', '"away"', null);
        $this->moveTimePastRecoveryInterval();

        $this->assertThrowsReason(StoreError::ValueNotIncrementable, fn() => $this->guarded->increment('mode', 1));
        self::assertTrue($this->guarded->getHealth()->available);
    }

    public function testHealthNamesWhatPausedIt(): void
    {
        self::assertEquals(new StoreHealth(true), $this->guarded->getHealth());

        $this->failOnce();
        $health = $this->guarded->getHealth();

        self::assertFalse($health->available);
        self::assertStringEndsWith('connection reset', (string) $health->lastFailure);
        self::assertEquals($this->clock->getNow(), $health->lastFailureAt);
    }

    public function testRefusalKeepsBackendAvailable(): void
    {
        $this->engine->write('mode', '"away"', null);

        try {
            $this->guarded->increment('mode', 1);
            self::fail('A string cannot be counted.');
        } catch (StoreException $e) {
            self::assertSame(StoreError::ValueNotIncrementable, $e->reason);
        }

        self::assertTrue($this->guarded->getHealth()->available);
    }

    private function moveTimePastRecoveryInterval(): void
    {
        $this->clock->moveTo($this->clock->getMonotonicTime()->plus(Duration::seconds(6)));
    }

    private function failOnce(): void
    {
        $this->engine->simulateOutage('connection reset');

        try {
            $this->guarded->read('mode');
            self::fail('The engine is down.');
        } catch (StoreException $e) {
            self::assertSame(StoreError::Unreachable, $e->reason);
        }
    }
}
