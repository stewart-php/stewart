<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Ipc\Wire;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\JsonShapeError;
use Stewart\Contracts\Trigger\HaTrigger;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Ipc\Wire\TriggerSpecConverter;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(TriggerSpecConverter::class)]
final class TriggerSpecConverterTest extends TestCase
{
    use AssertsReason;

    public function testSpecKeepsSharingKeyAcrossWire(): void
    {
        $converter = new TriggerSpecConverter();
        $spec = TriggerSpec::fromSpec(HaTrigger::onSunset(), ['room' => 'hall']);

        $decoded = $converter->decodeValue($converter->encodeValue($spec), 'trigger');

        self::assertSame($spec->getSharingKey(), $decoded->getSharingKey());
    }

    #[DataProvider('provideBrokenSpecs')]
    public function testBrokenSpecIsRejected(mixed $encoded, JsonShapeError $reason): void
    {
        $this->assertThrowsReason($reason, static fn() => new TriggerSpecConverter()->decodeValue($encoded, 'trigger'));
    }

    /** @return iterable<string, array{mixed, JsonShapeError}> */
    public static function provideBrokenSpecs(): iterable
    {
        yield 'not an object' => ['sun', JsonShapeError::WrongType];
        yield 'triggers not a list' => [['triggers' => ['trigger' => 'sun'], 'variables' => []], JsonShapeError::WrongType];
        yield 'variables missing' => [['triggers' => [['trigger' => 'sun']]], JsonShapeError::WrongType];
        yield 'invalid trigger' => [['triggers' => [['entity_id' => 'light.hall']], 'variables' => []], JsonShapeError::UnexpectedValue];
        yield 'no triggers' => [['triggers' => [], 'variables' => []], JsonShapeError::UnexpectedValue];
    }
}
