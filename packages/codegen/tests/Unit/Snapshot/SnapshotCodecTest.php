<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Snapshot;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Registry\EntityRegistryEntry;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Codegen\Exception\CodegenError;
use Stewart\Codegen\Exception\CodegenException;
use Stewart\Codegen\Snapshot\Snapshot;
use Stewart\Codegen\Snapshot\SnapshotCodec;
use Stewart\Codegen\Tests\Fixtures\GoldenSnapshot;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\EntityState;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Logging\RecordingLogger;

#[CoversClass(SnapshotCodec::class)]
#[CoversClass(Snapshot::class)]
#[CoversClass(CodegenException::class)]
final class SnapshotCodecTest extends TestCase
{
    use AssertsReason;

    public function testFixtureMatchesEncoderOutput(): void
    {
        $snapshot = GoldenSnapshot::loadSnapshot();

        self::assertSame(file_get_contents(GoldenSnapshot::getSnapshotPath()), new SnapshotCodec(new EntityStateDecoder())->encodeSnapshot($snapshot));
    }

    public function testRoundTripIsLossless(): void
    {
        $snapshot = GoldenSnapshot::loadSnapshot();
        $again = new SnapshotCodec(new EntityStateDecoder())->decodeSnapshot(new SnapshotCodec(new EntityStateDecoder())->encodeSnapshot($snapshot), 'memory');

        self::assertEquals($snapshot, $again);
    }

    public function testSortsEverything(): void
    {
        $snapshot = Snapshot::fromParts(
            '2026.9.1',
            [new EntityState(new EntityId('light.b'), 'on'), new EntityState(new EntityId('light.a'), 'off')],
            [new EntityRegistryEntry(new EntityId('light.b')), new EntityRegistryEntry(new EntityId('light.a'))],
            ['switch' => [], 'light' => []],
        );

        self::assertSame(['light.a', 'light.b'], $snapshot->states->listEntityIds()->toStrings());
        self::assertSame(['light.a', 'light.b'], $snapshot->registry->mapToList(static fn(EntityRegistryEntry $entry): string => $entry->entityId->value));
        self::assertSame(['light', 'switch'], array_keys($snapshot->services));
    }

    public function testWholeNumberFloatStaysFloat(): void
    {
        $snapshot = Snapshot::fromParts('2026.9.1', [new EntityState(new EntityId('sensor.temp'), '20', ['apparent' => 20.0])], [], []);

        $again = new SnapshotCodec(new EntityStateDecoder())->decodeSnapshot(new SnapshotCodec(new EntityStateDecoder())->encodeSnapshot($snapshot), 'memory');

        self::assertSame(20.0, $again->states->find(new EntityId('sensor.temp'))?->getAttribute('apparent'));
    }

    public function testSkipsEntriesWithInvalidEntityIds(): void
    {
        $logger = new RecordingLogger();

        $snapshot = new SnapshotCodec(new EntityStateDecoder())->decodeSnapshot(json_encode([
            'ha_version' => '2026.9.1',
            'states' => [['entity_id' => 'light.hall', 'state' => 'on'], ['entity_id' => 'Not An Id', 'state' => 'on']],
            'registry' => [['entity_id' => 'light.hall'], ['entity_id' => '']],
        ], \JSON_THROW_ON_ERROR), 'memory', $logger);

        self::assertSame(['light.hall'], $snapshot->states->listEntityIds()->toStrings());
        self::assertCount(1, $snapshot->registry);
        self::assertCount(2, $logger->records);
    }

    public function testRejectsInvalidJson(): void
    {
        $e = $this->assertThrowsReason(CodegenError::SnapshotNotJson, fn() => new SnapshotCodec(new EntityStateDecoder())->decodeSnapshot('{not json', 'var/nonsense.json'));

        self::assertStringContainsString('var/nonsense.json is not valid JSON', $e->getMessage());
    }

    public function testRejectsJsonThatIsNoSnapshot(): void
    {
        $this->assertThrowsReason(CodegenError::NotASnapshot, fn() => new SnapshotCodec(new EntityStateDecoder())->decodeSnapshot('{"hello": "world"}', 'var/other.json'));
    }

}
