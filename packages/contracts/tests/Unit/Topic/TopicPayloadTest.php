<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Topic;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stewart\Contracts\Exception\TopicError;
use Stewart\Contracts\Exception\TopicException;
use Stewart\Contracts\Topic\TopicPayload;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(TopicPayload::class)]
#[CoversClass(TopicException::class)]
final class TopicPayloadTest extends TestCase
{
    use AssertsReason;

    /** @param array<mixed>|bool|float|int|string|null $payload */
    #[DataProvider('provideAcceptablePayloads')]
    public function testPlainDataIsAccepted(bool|int|float|string|array|null $payload): void
    {
        self::assertSame($payload, new TopicPayload($payload)->value);
    }

    /** @return iterable<string, array{array<mixed>|bool|float|int|string|null}> */
    public static function provideAcceptablePayloads(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['on'];
        yield 'float' => [21.5];
        yield 'nested arrays' => [['a' => [1, true, ['b' => null]]]];
    }

    public function testObjectIsRejectedWithItsPath(): void
    {
        $e = $this->assertThrowsReason(TopicError::PayloadInvalid, fn() => new TopicPayload(['a' => [1, new stdClass()]]));

        self::assertStringContainsString('Topic payload at payload[a][1] is stdClass; expected null, a finite scalar or an array of those.', $e->getMessage());
    }

    public function testNonFiniteFloatIsRejected(): void
    {
        $this->expectException(TopicException::class);
        $this->expectExceptionMessage('Topic payload at payload[ratio] is float(NAN); expected null, a finite scalar or an array of those.');

        new TopicPayload(['ratio' => \NAN]);
    }
}
