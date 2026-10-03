<?php

declare(strict_types=1);

namespace Stewart\Store\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\StoreError;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Store\StoreValueCodec;
use Stewart\Store\Tests\Fixtures\EmptyStorable;
use Stewart\Store\Tests\Fixtures\ListStorable;
use Stewart\Store\Tests\Fixtures\Thermostat;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(StoreValueCodec::class)]
#[CoversClass(StoreException::class)]
final class StoreValueCodecTest extends TestCase
{
    use AssertsReason;

    private StoreValueCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new StoreValueCodec();
    }

    public function testScalarsAreStoredAsThemselves(): void
    {
        self::assertSame('42', $this->codec->encodeValue('k', 42));
        self::assertSame('true', $this->codec->encodeValue('k', true));
        self::assertSame('"away"', $this->codec->encodeValue('k', 'away'));
        self::assertSame('21.5', $this->codec->encodeValue('k', 21.5));
    }

    public function testWholeFloatKeepsItsFraction(): void
    {
        self::assertSame('21.0', $this->codec->encodeValue('k', 21.0));
        self::assertSame(21.0, $this->codec->decodeFloat('k', '21.0'));
    }

    public function testStoredIntegerWidensToAFloat(): void
    {
        self::assertSame(21.0, $this->codec->decodeFloat('k', '21'));
    }

    public function testStoredFloatIsNotAnInteger(): void
    {
        $e = $this->assertThrowsReason(StoreError::TypeMismatch, fn() => $this->codec->decodeInt('target', '21.0'));

        self::assertStringContainsString('Key "target" holds a float, not an integer.', $e->getMessage());
    }

    public function testNumericLookingStringStaysAString(): void
    {
        self::assertSame('"42"', $this->codec->encodeValue('k', '42'));
        self::assertSame('42', $this->codec->decodeString('k', '"42"'));

        $this->assertThrowsReason(StoreError::TypeMismatch, fn() => $this->codec->decodeInt('k', '"42"'));
    }

    public function testObjectRoundTripsThroughItsOwnShape(): void
    {
        $encoded = $this->codec->encodeValue('t', new Thermostat(21.5, 'heat'));

        self::assertSame('{"target":21.5,"mode":"heat"}', $encoded);

        $decoded = $this->codec->decodeObject('t', $encoded, Thermostat::class);

        self::assertSame(21.5, $decoded->target);
        self::assertSame('heat', $decoded->mode);
    }

    public function testObjectWithNothingToStoreIsStillAnObject(): void
    {
        self::assertSame('{}', $this->codec->encodeValue('e', new EmptyStorable()));

        $this->codec->decodeObject('e', '{}', EmptyStorable::class);

        $this->addToAssertionCount(1);
    }

    public function testObjectThatStoresAListIsRefused(): void
    {
        $e = $this->assertThrowsReason(StoreError::ValueNotAMap, fn() => $this->codec->encodeValue('l', new ListStorable()));

        self::assertStringContainsString('toStorage() returned a list for key "l"', $e->getMessage());
    }

    public function testStoredListIsNotAnObject(): void
    {
        $e = $this->assertThrowsReason(StoreError::TypeMismatch, fn() => $this->codec->decodeObject('t', '["heat"]', Thermostat::class));

        self::assertStringContainsString('Key "t" holds a list, not an object.', $e->getMessage());
    }

    public function testInfiniteFloatCannotBeStored(): void
    {
        $e = $this->assertThrowsReason(StoreError::ValueNotEncodable, fn() => $this->codec->encodeValue('k', \INF));

        self::assertStringContainsString('The value for key "k" is not JSON-encodable', $e->getMessage());
    }

    public function testInvalidUtf8IsStoredWithReplacementCharacters(): void
    {
        self::assertSame("\"\u{FFFD}1\"", $this->codec->encodeValue('k', "\xB1\x31"));
    }

    public function testMalformedJsonNamesTheKey(): void
    {
        $e = $this->assertThrowsReason(StoreError::ValueMalformed, fn() => $this->codec->decodeString('k', '{oops'));

        self::assertStringContainsString('Key "k" does not hold valid JSON', $e->getMessage());
    }

    public function testObjectTheClassRejectsKeepsTheCause(): void
    {
        try {
            $this->codec->decodeObject('t', '{"other":1}', Thermostat::class);
        } catch (StoreException $e) {
            self::assertStringContainsString('fromStorage() rejected the value of key "t" (handle the old shape or delete the key): target is missing', $e->getMessage());
            self::assertNotNull($e->getPrevious(), 'The class\'s own complaint is what a reader needs to see.');

            return;
        }

        self::fail('A shape the class refuses must not read back as an object.');
    }

    public function testStoredNullMatchesNoType(): void
    {
        $e = $this->assertThrowsReason(StoreError::TypeMismatch, fn() => $this->codec->decodeString('k', 'null'));

        self::assertStringContainsString('Key "k" holds null, not a string.', $e->getMessage());
    }

    public function testBooleanIsNotAnInteger(): void
    {
        $e = $this->assertThrowsReason(StoreError::TypeMismatch, fn() => $this->codec->decodeInt('k', 'true'));

        self::assertStringContainsString('Key "k" holds a boolean, not an integer.', $e->getMessage());
    }

    public function testSlashesAndUnicodeAreStoredUnescaped(): void
    {
        self::assertSame('"a/b"', $this->codec->encodeValue('k', 'a/b'));
        self::assertSame('"kürbis"', $this->codec->encodeValue('k', 'kürbis'));
    }
}
