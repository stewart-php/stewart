<?php

declare(strict_types=1);

namespace Stewart\Testing\Store;

use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\StoreError;
use Stewart\Contracts\Time\Duration;
use Stewart\Store\StoreBackend;
use Stewart\Testing\Exception\AssertsReason;

// Not named *Test: PHPUnit rejects an abstract class in a test file.
abstract class StoreBackendContract extends TestCase
{
    use AssertsReason;

    protected StoreBackend $backendUnderTest;

    abstract protected function createBackend(): StoreBackend;

    abstract protected function advanceTime(Duration $span): void;

    protected function setUp(): void
    {
        $this->backendUnderTest = $this->createBackend();
    }

    public function testAMissingKeyReadsAsNull(): void
    {
        self::assertNull($this->backendUnderTest->read($this->buildPrefixedKey('absent')));
        self::assertFalse($this->backendUnderTest->exists($this->buildPrefixedKey('absent')));
    }

    public function testAValueComesBackByteForByte(): void
    {
        $raw = '{"target":21.5,"mode":"héat/0"}';

        $this->backendUnderTest->write($this->buildPrefixedKey('thermostat'), $raw, null);

        self::assertSame($raw, $this->backendUnderTest->read($this->buildPrefixedKey('thermostat')));
        self::assertTrue($this->backendUnderTest->exists($this->buildPrefixedKey('thermostat')));
    }

    public function testAWriteReplacesWhatWasThere(): void
    {
        $this->backendUnderTest->write($this->buildPrefixedKey('mode'), '"away"', null);
        $this->backendUnderTest->write($this->buildPrefixedKey('mode'), '"home"', null);

        self::assertSame('"home"', $this->backendUnderTest->read($this->buildPrefixedKey('mode')));
    }

    public function testRemovingLeavesNothingAndIsSafeToRepeat(): void
    {
        $this->backendUnderTest->write($this->buildPrefixedKey('mode'), '"away"', null);
        $this->backendUnderTest->remove($this->buildPrefixedKey('mode'));
        $this->backendUnderTest->remove($this->buildPrefixedKey('mode'));

        self::assertNull($this->backendUnderTest->read($this->buildPrefixedKey('mode')));
    }

    public function testATtlExpiresTheValue(): void
    {
        $this->backendUnderTest->write($this->buildPrefixedKey('seen'), 'true', Duration::milliseconds(50));

        self::assertSame('true', $this->backendUnderTest->read($this->buildPrefixedKey('seen')));

        $this->advanceTime(Duration::milliseconds(150));

        self::assertNull($this->backendUnderTest->read($this->buildPrefixedKey('seen')));
        self::assertFalse($this->backendUnderTest->exists($this->buildPrefixedKey('seen')));
        self::assertNotContains($this->buildPrefixedKey('seen'), $this->backendUnderTest->keysWithPrefix($this->getPrefix()));
    }

    public function testWritingWithoutATtlClearsOneTheKeyHad(): void
    {
        $this->backendUnderTest->write($this->buildPrefixedKey('seen'), 'true', Duration::milliseconds(50));
        $this->backendUnderTest->write($this->buildPrefixedKey('seen'), 'false', null);

        $this->advanceTime(Duration::milliseconds(150));

        self::assertSame('false', $this->backendUnderTest->read($this->buildPrefixedKey('seen')));
    }

    public function testCountingCreatesTheKeyItIsMissing(): void
    {
        self::assertSame(1, $this->backendUnderTest->increment($this->buildPrefixedKey('opens'), 1));
        self::assertSame(4, $this->backendUnderTest->increment($this->buildPrefixedKey('opens'), 3));
        self::assertSame(3, $this->backendUnderTest->increment($this->buildPrefixedKey('opens'), -1));
        self::assertSame('3', $this->backendUnderTest->read($this->buildPrefixedKey('opens')));
    }

    public function testCountingKeepsAnExpiry(): void
    {
        $this->backendUnderTest->write($this->buildPrefixedKey('recent'), '1', Duration::milliseconds(50));
        $this->backendUnderTest->increment($this->buildPrefixedKey('recent'), 1);

        $this->advanceTime(Duration::milliseconds(150));

        self::assertNull($this->backendUnderTest->read($this->buildPrefixedKey('recent')));
    }

    public function testCountingWhatIsNotAWholeNumberIsRefused(): void
    {
        $this->backendUnderTest->write($this->buildPrefixedKey('mode'), '"away"', null);

        $this->assertThrowsReason(StoreError::ValueNotIncrementable, fn() => $this->backendUnderTest->increment($this->buildPrefixedKey('mode'), 1));
    }

    public function testCountingANumberWithANewlineIsRefused(): void
    {
        $this->backendUnderTest->write($this->buildPrefixedKey('visits'), "5\n", null);

        $this->assertThrowsReason(StoreError::ValueNotIncrementable, fn() => $this->backendUnderTest->increment($this->buildPrefixedKey('visits'), 1));
    }

    public function testCountingAFloatIsRefused(): void
    {
        $this->backendUnderTest->write($this->buildPrefixedKey('target'), '21.5', null);

        $this->assertThrowsReason(StoreError::ValueNotIncrementable, fn() => $this->backendUnderTest->increment($this->buildPrefixedKey('target'), 1));
    }

    public function testAPrefixScanReturnsOnlyThatPrefix(): void
    {
        $this->backendUnderTest->write($this->buildPrefixedKey('a'), '1', null);
        $this->backendUnderTest->write($this->buildPrefixedKey('b'), '2', null);
        $this->backendUnderTest->write($this->getPrefix() . 'deeper:c', '3', null);
        $this->backendUnderTest->write($this->getOtherPrefix() . 'a', '4', null);

        $found = $this->backendUnderTest->keysWithPrefix($this->getPrefix());
        sort($found);

        self::assertSame([$this->buildPrefixedKey('a'), $this->buildPrefixedKey('b'), $this->getPrefix() . 'deeper:c'], $found);
    }

    public function testAPrefixScanOfAnEmptyScopeIsEmpty(): void
    {
        self::assertSame([], $this->backendUnderTest->keysWithPrefix($this->getPrefix()));
    }

    public function testRemovingAPrefixLeavesOtherPrefixesAlone(): void
    {
        $this->backendUnderTest->write($this->buildPrefixedKey('a'), '1', null);
        $this->backendUnderTest->write($this->getOtherPrefix() . 'a', '2', null);

        $this->backendUnderTest->removeByPrefix($this->getPrefix());

        self::assertSame([], $this->backendUnderTest->keysWithPrefix($this->getPrefix()));
        self::assertSame('2', $this->backendUnderTest->read($this->getOtherPrefix() . 'a'));
    }

    public function testProbingSucceedsAgainstAnEngineThatIsThere(): void
    {
        $this->backendUnderTest->probe();

        $this->addToAssertionCount(1);
    }

    protected function getPrefix(): string
    {
        return 'stewart:app:heating:';
    }

    protected function getOtherPrefix(): string
    {
        return 'stewart:global:';
    }

    private function buildPrefixedKey(string $key): string
    {
        return $this->getPrefix() . $key;
    }
}
