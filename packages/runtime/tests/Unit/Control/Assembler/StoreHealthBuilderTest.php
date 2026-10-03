<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Control\Assembler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Time\Instant;
use Stewart\Runtime\Control\Assembler\StoreHealthBuilder;
use Stewart\Runtime\Tests\Fixtures\Control\BrokerStateFixture;
use Stewart\Store\StoreHealth;

#[CoversClass(StoreHealthBuilder::class)]
final class StoreHealthBuilderTest extends TestCase
{
    private BrokerStateFixture $broker;

    protected function setUp(): void
    {
        $this->broker = new BrokerStateFixture();
    }

    protected function tearDown(): void
    {
        $this->broker->stopEverything();
    }

    public function testWithoutPersistenceThereIsNoStoreToReport(): void
    {
        self::assertNull(new StoreHealthBuilder($this->broker->pools->slots, storeConfigured: false)->buildStoreHealth());
    }

    public function testStoreDownWhenAnyWorkerReportsIt(): void
    {
        $this->broker->startWorker(0);
        $this->broker->startWorker(1);
        $earlier = Instant::fromIso('2026-09-26T10:00:00Z');
        $later = Instant::fromIso('2026-09-26T10:05:00Z');

        $this->broker->reportStoreHealth(0, new StoreHealth(true, 'timed out', $earlier));
        $this->broker->reportStoreHealth(1, new StoreHealth(false, 'connection refused', $later));

        self::assertEquals(new StoreHealth(false, 'connection refused', $later), $this->createBuilder()->buildStoreHealth());

        $this->broker->reportStoreHealth(1, new StoreHealth(true, 'connection refused', $later));

        self::assertTrue($this->createBuilder()->buildStoreHealth()?->available);
    }

    public function testStoreNoWorkerHasReportedOnIsUp(): void
    {
        self::assertEquals(new StoreHealth(true), $this->createBuilder()->buildStoreHealth());
    }

    private function createBuilder(): StoreHealthBuilder
    {
        return new StoreHealthBuilder($this->broker->pools->slots, storeConfigured: true);
    }
}
