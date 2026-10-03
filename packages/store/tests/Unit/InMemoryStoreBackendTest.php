<?php

declare(strict_types=1);

namespace Stewart\Store\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Stewart\Contracts\Exception\StoreError;
use Stewart\Contracts\Time\Duration;
use Stewart\Store\StoreBackend;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Store\InMemoryStoreBackend;
use Stewart\Testing\Store\StoreBackendContract;
use Stewart\Testing\Time\ManualTimers;

#[CoversClass(InMemoryStoreBackend::class)]
final class InMemoryStoreBackendTest extends StoreBackendContract
{
    use AssertsReason;

    private ManualTimers $timers;

    public function testBrokenEngineFailsEveryOperation(): void
    {
        $this->backendUnderTest->write($this->getPrefix() . 'mode', '"away"', null);

        self::assertInstanceOf(InMemoryStoreBackend::class, $this->backendUnderTest);
        $this->backendUnderTest->simulateOutage('connection reset');

        $this->assertThrowsReason(StoreError::Unreachable, fn() => $this->backendUnderTest->read($this->getPrefix() . 'mode'));
    }

    public function testRepairedEngineStillHoldsWhatItHeld(): void
    {
        self::assertInstanceOf(InMemoryStoreBackend::class, $this->backendUnderTest);

        $this->backendUnderTest->write($this->getPrefix() . 'mode', '"away"', null);
        $this->backendUnderTest->simulateOutage('connection reset');
        $this->backendUnderTest->endOutage();

        self::assertSame('"away"', $this->backendUnderTest->read($this->getPrefix() . 'mode'));
    }

    protected function createBackend(): StoreBackend
    {
        $this->timers = new ManualTimers();

        return new InMemoryStoreBackend($this->timers->clock);
    }

    protected function advanceTime(Duration $span): void
    {
        $this->timers->delay($span);
    }
}
