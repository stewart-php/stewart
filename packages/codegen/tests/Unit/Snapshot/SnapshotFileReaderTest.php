<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Snapshot;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Codegen\Exception\CodegenError;
use Stewart\Codegen\Snapshot\SnapshotCodec;
use Stewart\Codegen\Snapshot\SnapshotFileReader;
use Stewart\Codegen\Tests\Fixtures\GoldenSnapshot;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(SnapshotFileReader::class)]
final class SnapshotFileReaderTest extends TestCase
{
    use AssertsReason;

    public function testReadsAndDecodesTheFile(): void
    {
        $codec = new SnapshotCodec(new EntityStateDecoder());

        $snapshot = new SnapshotFileReader($codec)->readSnapshot(GoldenSnapshot::getSnapshotPath());

        self::assertSame(file_get_contents(GoldenSnapshot::getSnapshotPath()), $codec->encodeSnapshot($snapshot));
    }

    public function testMissingFileIsUnreadable(): void
    {
        $e = $this->assertThrowsReason(CodegenError::SnapshotUnreadable, fn() => new SnapshotFileReader(new SnapshotCodec(new EntityStateDecoder()))->readSnapshot('/nowhere/snapshot.json'));

        self::assertStringContainsString('cannot be read', $e->getMessage());
    }
}
