<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Codegen\Emitter\EmitContext;
use Stewart\Codegen\Entity\EntityFilter;
use Stewart\Codegen\Entity\EntityModelFactory;
use Stewart\Codegen\Entity\UnmatchedEntitySelector;
use Stewart\Codegen\Entity\UnmatchedRename;
use Stewart\Codegen\Exception\CodegenError;
use Stewart\Codegen\GenerationOptions;
use Stewart\Codegen\GenerationTarget;
use Stewart\Codegen\Model\GenerationModelFactory;
use Stewart\Codegen\Model\GenerationWarning;
use Stewart\Codegen\Output\GenerationReport;
use Stewart\Codegen\Snapshot\Snapshot;
use Stewart\Codegen\Snapshot\SnapshotCodec;
use Stewart\Codegen\Tests\Fixtures\GenerationRun;
use Stewart\Codegen\Tests\Fixtures\GoldenSnapshot;
use Stewart\Testing\Exception\AssertsReason;
use Stewart\Testing\Filesystem\TempDirectory;

#[CoversClass(GenerationModelFactory::class)]
#[CoversClass(EntityModelFactory::class)]
#[CoversClass(EntityFilter::class)]
#[CoversClass(EmitContext::class)]
#[CoversClass(UnmatchedRename::class)]
#[CoversClass(UnmatchedEntitySelector::class)]
final class GenerationModelFactoryTest extends TestCase
{
    use AssertsReason;

    private TempDirectory $temp;

    protected function setUp(): void
    {
        $this->temp = TempDirectory::createWithPrefix('stewart-model-');
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    public function testDomainsFoldingToOneStemAreNumbered(): void
    {
        $this->writeFiles(self::createSnapshot(['foo_bar.one', 'foobar.two']));

        self::assertFileExists($this->temp->getFilePath('FooBarEntities.php'));
        self::assertFileExists($this->temp->getFilePath('Foobar2Entities.php'));
        self::assertStringContainsString('public Foobar2Entities $foobar2 {', (string) file_get_contents($this->temp->getFilePath('Entities.php')));
    }

    public function testDomainNamedEntityAliasesItsImport(): void
    {
        $this->writeFiles(self::createSnapshot(['entity.thing']));

        $state = (string) file_get_contents($this->temp->getFilePath('EntityState.php'));

        self::assertStringContainsString('use Stewart\Contracts\State\EntityState as EntityStateContract;', $state);
        self::assertStringContainsString('public function __construct(public EntityStateContract $raw)', $state);
    }

    public function testRenameTakingNaturalNameFails(): void
    {
        $this->assertThrowsReason(
            CodegenError::RenameCollision,
            fn() => $this->writeFiles(self::createSnapshot(['light.hall', 'light.hall_2']), ['light.hall_2' => 'hall']),
        );
    }

    public function testRenamesSharingANameFail(): void
    {
        $this->assertThrowsReason(
            CodegenError::RenameCollision,
            fn() => $this->writeFiles(self::createSnapshot(['light.hall', 'light.porch']), ['light.hall' => 'lamp', 'light.porch' => 'Lamp']),
        );
    }

    public function testRenameToReservedMemberFails(): void
    {
        $this->assertThrowsReason(
            CodegenError::RenameReserved,
            fn() => $this->writeFiles(self::createSnapshot(['light.hall']), ['light.hall' => 'ha']),
        );
    }

    public function testRenamedEntityLeavesNaturalNameFree(): void
    {
        $this->writeFiles(self::createSnapshot(['light.hall', 'light.hall_2', 'light.porch2', 'light.porch_2']), ['light.hall' => 'hallCeiling', 'light.porch_2' => 'porchTwo']);

        $entities = (string) file_get_contents($this->temp->getFilePath('LightEntities.php'));

        self::assertStringContainsString('public LightEntity $hall2 {', $entities);
        self::assertStringContainsString('public LightEntity $porch2 {', $entities);
        self::assertStringContainsString('public LightEntity $porchTwo {', $entities);
    }

    public function testRenameOfUngeneratedEntityIsWarned(): void
    {
        $report = $this->writeFiles(GoldenSnapshot::loadSnapshot(), ['light.nowhere' => 'nowhere', 'light.debug_strip' => 'strip']);

        self::assertSame(
            ['The rename of light.nowhere to nowhere matches no generated entity.', 'The rename of light.debug_strip to strip matches no generated entity.'],
            self::describeWarnings($report),
        );
    }

    public function testIncludeMatchingNoEntityIsWarned(): void
    {
        $report = $this->writeFiles(GoldenSnapshot::loadSnapshot(), include: ['light.*', 'cover.*']);

        self::assertSame(['The codegen include cover.* matches no entity.'], self::describeWarnings($report));
    }

    public function testIncludeOfDisabledEntityIsNotWarned(): void
    {
        $report = $this->writeFiles(GoldenSnapshot::loadSnapshot(), include: ['light.attic', 'switch.*']);

        self::assertSame([], self::describeWarnings($report));
    }

    /**
     * @param array<string, string> $renames
     * @param list<string> $include
     */
    private function writeFiles(Snapshot $snapshot, array $renames = [], array $include = ['*']): GenerationReport
    {
        return new GenerationRun(
            GoldenSnapshot::resolveGenerator(),
            new GenerationTarget('Acme\Home', $this->temp->path),
            new GenerationOptions(EntityFilter::fromPatterns($include, ['light.debug_*']), $renames),
        )->writeFiles($snapshot);
    }

    /** @param list<string> $entityIds */
    private static function createSnapshot(array $entityIds): Snapshot
    {
        $states = array_map(static fn(string $entityId): array => ['entity_id' => $entityId, 'state' => 'on', 'attributes' => []], $entityIds);

        return new SnapshotCodec(new EntityStateDecoder())->decodeSnapshot(json_encode(['states' => $states], \JSON_THROW_ON_ERROR), 'memory');
    }

    /** @return list<string> */
    private static function describeWarnings(GenerationReport $report): array
    {
        return $report->warnings->mapToList(static fn(GenerationWarning $warning): string => $warning->describeWarning());
    }
}
