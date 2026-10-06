<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Ipc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Exception\TransportError;
use Stewart\Runtime\Ipc\Message\Bootstrap;
use Stewart\Runtime\Ipc\Message\Ping;
use Stewart\Runtime\Ipc\Wire\IpcMessageCatalog;
use Stewart\Runtime\Tests\Fixtures\Ipc\SecondPingMessage;
use Stewart\Runtime\Tests\Fixtures\Ipc\UntaggedMessage;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(IpcMessageCatalog::class)]
final class IpcMessageCatalogTest extends TestCase
{
    use AssertsReason;

    public function testScanFindsTaggedMessagesBothWays(): void
    {
        $catalog = IpcMessageCatalog::scanMessageDirectory();

        self::assertSame('bootstrap', $catalog->findTagForClass(Bootstrap::class));
        self::assertSame(Bootstrap::class, $catalog->findClassForTag('bootstrap'));
    }

    public function testScanListsMessagesSortedByTag(): void
    {
        $catalog = IpcMessageCatalog::scanMessageDirectory();
        $tags = array_map($catalog->findTagForClass(...), $catalog->listMessageClasses());
        $sorted = $tags;
        sort($sorted);

        self::assertCount(31, $tags);
        self::assertSame($sorted, $tags);
    }

    public function testUnknownTagAndClassFindNothing(): void
    {
        $catalog = IpcMessageCatalog::fromMessageClasses([Ping::class]);

        self::assertNull($catalog->findClassForTag('dance'));
        self::assertNull($catalog->findTagForClass(Bootstrap::class));
    }

    public function testRefusesMessageWithoutAttribute(): void
    {
        $this->assertThrowsReason(TransportError::MessageTagMissing, fn() => IpcMessageCatalog::fromMessageClasses([UntaggedMessage::class]));
    }

    public function testRefusesTwoMessagesWithOneTag(): void
    {
        $this->assertThrowsReason(TransportError::MessageTagDuplicated, fn() => IpcMessageCatalog::fromMessageClasses([Ping::class, SecondPingMessage::class]));
    }
}
