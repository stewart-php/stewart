<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Snapshot;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Codegen\Exception\CodegenError;
use Stewart\Codegen\Snapshot\SnapshotCodec;
use Stewart\Codegen\Snapshot\SnapshotFileWriter;
use Stewart\Codegen\Tests\Fixtures\GoldenSnapshot;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Filesystem\TempDirectory;

#[CoversClass(SnapshotFileWriter::class)]
final class SnapshotFileWriterTest extends TestCase
{
    use AssertsReason;

    private TempDirectory $temp;

    protected function setUp(): void
    {
        $this->temp = TempDirectory::createWithPrefix('stewart-snapshot-');
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    public function testWritesTheEncodedSnapshot(): void
    {
        $path = $this->temp->getFilePath('snapshot.json');

        new SnapshotFileWriter(new SnapshotCodec(new EntityStateDecoder()))->writeSnapshot($path, GoldenSnapshot::loadSnapshot());

        self::assertFileEquals(GoldenSnapshot::getSnapshotPath(), $path);
    }

    public function testUnwritablePathFails(): void
    {
        $this->assertThrowsReason(CodegenError::FileNotWritable, fn() => @new SnapshotFileWriter(new SnapshotCodec(new EntityStateDecoder()))->writeSnapshot($this->temp->getFilePath('missing/dir/snapshot.json'), GoldenSnapshot::loadSnapshot()));
    }
}
