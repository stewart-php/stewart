<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker\Deploy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Broker\Deploy\CommitId;
use Stewart\Runtime\Exception\DeployError;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(CommitId::class)]
final class CommitIdTest extends TestCase
{
    use AssertsReason;

    public function testSha1AndSha256HashesAreAccepted(): void
    {
        self::assertSame(str_repeat('a', 40), new CommitId(str_repeat('a', 40))->value);
        self::assertSame(str_repeat('0', 64), new CommitId(str_repeat('0', 64))->value);
    }

    /** @return iterable<string, array{string}> */
    public static function provideNonHashes(): iterable
    {
        yield 'short hash' => ['abc1234'];
        yield 'uppercase' => [str_repeat('A', 40)];
        yield 'branch name' => ['main'];
        yield 'empty' => [''];
    }

    #[DataProvider('provideNonHashes')]
    public function testNonHashIsRefused(string $value): void
    {
        self::assertThrowsReason(DeployError::CommitIdInvalid, static fn() => new CommitId($value));
    }
}
