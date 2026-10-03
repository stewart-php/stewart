<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Stewart\Codegen\Emitter\EmitContext;
use Stewart\Codegen\Emitter\EntitiesRootEmitter;
use Stewart\Codegen\Emitter\Entity\DomainEntitiesEmitter;
use Stewart\Codegen\Emitter\Entity\EntityHandleEmitter;
use Stewart\Codegen\Emitter\Entity\EntityStateEmitter;
use Stewart\Codegen\Emitter\GeneratedCodePrinter;
use Stewart\Codegen\Emitter\ManifestEmitter;
use Stewart\Codegen\Emitter\PhpStormMetaEmitter;
use Stewart\Codegen\Emitter\RootEmitter;
use Stewart\Codegen\Emitter\Service\DomainServicesEmitter;
use Stewart\Codegen\Emitter\Service\ServiceMethodEmitter;
use Stewart\Codegen\Emitter\ServicesRootEmitter;
use Stewart\Codegen\Emitter\SourceTree;
use Stewart\Codegen\Generator;
use Stewart\Codegen\Model\GenerationModelFactory;
use Stewart\Codegen\Tests\Fixtures\GeneratedTreeComparison;
use Stewart\Codegen\Tests\Fixtures\GoldenSnapshot;
use Stewart\Testing\Filesystem\TempDirectory;

#[CoversClass(Generator::class)]
#[CoversClass(GenerationModelFactory::class)]
#[CoversClass(RootEmitter::class)]
#[CoversClass(DomainEntitiesEmitter::class)]
#[CoversClass(EntityHandleEmitter::class)]
#[CoversClass(EntityStateEmitter::class)]
#[CoversClass(DomainServicesEmitter::class)]
#[CoversClass(EntitiesRootEmitter::class)]
#[CoversClass(ServicesRootEmitter::class)]
#[CoversClass(ServiceMethodEmitter::class)]
#[CoversClass(ManifestEmitter::class)]
#[CoversClass(PhpStormMetaEmitter::class)]
#[CoversClass(GeneratedCodePrinter::class)]
#[CoversClass(EmitContext::class)]
#[CoversClass(SourceTree::class)]
final class GeneratorGoldenTest extends TestCase
{
    public function testSnapshotRendersTheCommittedTree(): void
    {
        new GeneratedTreeComparison(GoldenSnapshot::createGenerationRun())
            ->assertCommittedTreeMatches(GoldenSnapshot::loadSnapshot());
    }

    public function testRenderingTwiceGivesTheSameBytes(): void
    {
        $snapshot = GoldenSnapshot::loadSnapshot();
        $temp = TempDirectory::createWithPrefix('stewart-determinism-');

        try {
            $generation = GoldenSnapshot::createGenerationRun($temp->path);
            $generation->writeFiles($snapshot);

            self::assertTrue($generation->checkAgainstSnapshot($snapshot)->isClean());
        } finally {
            $temp->remove();
        }
    }

    /** @return iterable<string, array{int}> */
    public static function provideShuffleSeeds(): iterable
    {
        yield 'seed 1' => [1];
        yield 'seed 42' => [42];
        yield 'seed 2026' => [2026];
    }

    #[DataProvider('provideShuffleSeeds')]
    public function testInputOrderDoesNotChangeTheOutput(int $seed): void
    {
        $snapshot = json_decode((string) file_get_contents(GoldenSnapshot::getSnapshotPath()), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($snapshot));
        $temp = TempDirectory::createWithPrefix('stewart-shuffled-');

        try {
            $shuffledPath = $temp->path . '/snapshot.json';
            file_put_contents($shuffledPath, json_encode(self::shuffleSnapshot($snapshot, new Randomizer(new Mt19937($seed))), \JSON_THROW_ON_ERROR));

            new GeneratedTreeComparison(GoldenSnapshot::createGenerationRun())
                ->assertCommittedTreeMatches(GoldenSnapshot::readSnapshotFile($shuffledPath));
        } finally {
            $temp->remove();
        }
    }

    /**
     * @param array<mixed> $snapshot
     * @return array<mixed>
     */
    private static function shuffleSnapshot(array $snapshot, Randomizer $randomizer): array
    {
        \assert(\is_array($snapshot['states']) && \is_array($snapshot['registry']) && \is_array($snapshot['services']));

        $snapshot['states'] = array_map(
            static fn(mixed $state): mixed => \is_array($state) && \is_array($state['attributes'] ?? null)
                ? [...$state, 'attributes' => self::shuffleKeys($state['attributes'], $randomizer)]
                : $state,
            $randomizer->shuffleArray($snapshot['states']),
        );
        $snapshot['registry'] = $randomizer->shuffleArray($snapshot['registry']);
        $snapshot['services'] = array_map(
            static fn(mixed $services): mixed => \is_array($services)
                ? array_map(static fn(mixed $service): mixed => self::shuffleFields($service, $randomizer), self::shuffleKeys($services, $randomizer))
                : $services,
            self::shuffleKeys($snapshot['services'], $randomizer),
        );

        return $snapshot;
    }

    private static function shuffleFields(mixed $node, Randomizer $randomizer): mixed
    {
        if (!\is_array($node) || !\is_array($node['fields'] ?? null)) {
            return $node;
        }

        $node['fields'] = array_map(
            static fn(mixed $field): mixed => self::shuffleFields($field, $randomizer),
            self::shuffleKeys($node['fields'], $randomizer),
        );

        return $node;
    }

    /**
     * @param array<array-key, mixed> $map
     * @return array<array-key, mixed>
     */
    private static function shuffleKeys(array $map, Randomizer $randomizer): array
    {
        $shuffled = [];

        /** @var list<array-key> $keys */
        $keys = $randomizer->shuffleArray(array_keys($map));

        foreach ($keys as $key) {
            $shuffled[$key] = $map[$key];
        }

        return $shuffled;
    }
}
