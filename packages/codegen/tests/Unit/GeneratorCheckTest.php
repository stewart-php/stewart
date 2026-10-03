<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Codegen\Generator;
use Stewart\Codegen\Output\CheckResult;
use Stewart\Codegen\Output\Collection\FileChangeCollection;
use Stewart\Codegen\Output\FileChange;
use Stewart\Codegen\Output\FileOutcome;
use Stewart\Codegen\Snapshot\Snapshot;
use Stewart\Codegen\Snapshot\SnapshotCodec;
use Stewart\Codegen\Tests\Fixtures\GoldenSnapshot;
use Stewart\Testing\Filesystem\TempDirectory;

#[CoversClass(CheckResult::class)]
#[CoversClass(Generator::class)]
#[CoversClass(FileChangeCollection::class)]
final class GeneratorCheckTest extends TestCase
{
    public function testCommittedTreeIsUpToDate(): void
    {
        $result = GoldenSnapshot::createGenerationRun()->checkAgainstSnapshot(GoldenSnapshot::loadSnapshot());

        self::assertTrue($result->isClean());
        self::assertSame([], $result->listStaleFiles()->listValues());
    }

    public function testEmptyDirectoryGetsEveryFile(): void
    {
        $temp = TempDirectory::createWithPrefix('stewart-check-');

        try {
            $result = GoldenSnapshot::createGenerationRun($temp->getFilePath('empty'))->checkAgainstSnapshot(GoldenSnapshot::loadSnapshot());
        } finally {
            $temp->remove();
        }

        self::assertFalse($result->isClean());
        self::assertSame(
            array_fill(0, \count($result->listStaleFiles()->listValues()), FileOutcome::Created),
            array_map(static fn(FileChange $change): FileOutcome => $change->outcome, $result->listStaleFiles()->listValues()),
        );
    }

    public function testNewEntityMakesTreeStale(): void
    {
        $result = GoldenSnapshot::createGenerationRun()->checkAgainstSnapshot(self::createSnapshotWithState(['entity_id' => 'light.landing', 'state' => 'off']));

        self::assertFalse($result->isClean());
        self::assertContains('Manifest.php', array_map(static fn(FileChange $change): string => basename($change->path), $result->listStaleFiles()->listValues()));
    }

    public function testExcludedEntityTouchesOnlyManifest(): void
    {
        $result = GoldenSnapshot::createGenerationRun()->checkAgainstSnapshot(self::createSnapshotWithState(['entity_id' => 'light.debug_extra', 'state' => 'off']));

        self::assertSame(['Manifest.php'], array_map(static fn(FileChange $change): string => basename($change->path), $result->listStaleFiles()->listValues()));
    }

    public function testIdleLightsKeepTheirAccessors(): void
    {
        $raw = self::loadRawSnapshotData();

        foreach ($raw['states'] as $index => $state) {
            $entityId = $state['entity_id'] ?? null;

            if (\is_string($entityId) && str_starts_with($entityId, 'light.')) {
                $raw['states'][$index]['state'] = 'off';
                $raw['states'][$index]['attributes'] = array_intersect_key((array) ($state['attributes'] ?? []), ['friendly_name' => true]);
            }
        }

        $result = GoldenSnapshot::createGenerationRun()->checkAgainstSnapshot(self::decodeSnapshot($raw));

        self::assertSame([], array_map(static fn(FileChange $change): string => $change->path, $result->listStaleFiles()->listValues()));
    }

    /** @param array<string, mixed> $state */
    private static function createSnapshotWithState(array $state): Snapshot
    {
        $raw = self::loadRawSnapshotData();
        $raw['states'][] = $state;

        return self::decodeSnapshot($raw);
    }

    /** @return array{states: list<array<string, mixed>>, services: array<string, array<string, mixed>>, ...} */
    private static function loadRawSnapshotData(): array
    {
        /** @var array{states: list<array<string, mixed>>, services: array<string, array<string, mixed>>} $raw */
        $raw = json_decode((string) file_get_contents(GoldenSnapshot::getSnapshotPath()), true, 512, \JSON_THROW_ON_ERROR);

        return $raw;
    }

    /** @param array<string, mixed> $raw */
    private static function decodeSnapshot(array $raw): Snapshot
    {
        return new SnapshotCodec(new EntityStateDecoder())->decodeSnapshot(json_encode($raw, \JSON_THROW_ON_ERROR), 'memory');
    }
}
