<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Codegen\Emitter\EmitContext;
use Stewart\Codegen\Entity\EntityInclusionRules;
use Stewart\Codegen\Entity\UnmatchedEntitySelector;
use Stewart\Codegen\GenerationOptions;
use Stewart\Codegen\GenerationTarget;
use Stewart\Codegen\Model\GenerationModelFactory;
use Stewart\Codegen\Model\GenerationWarning;
use Stewart\Codegen\Output\GenerationReport;
use Stewart\Codegen\Snapshot\Snapshot;
use Stewart\Codegen\Snapshot\SnapshotCodec;
use Stewart\Codegen\Tests\Fixtures\GenerationRun;
use Stewart\Codegen\Tests\Fixtures\GoldenSnapshot;
use Stewart\Testing\Filesystem\TempDirectory;

#[CoversClass(GenerationModelFactory::class)]
#[CoversClass(EntityInclusionRules::class)]
#[CoversClass(EmitContext::class)]
#[CoversClass(UnmatchedEntitySelector::class)]
final class GenerationModelFactoryTest extends TestCase
{
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

    public function testMetaFileCompletesEachDomainLookup(): void
    {
        $this->writeFiles(self::createSnapshot(['light.porch_2', 'switch.fan', 'light.hall']));

        $meta = (string) file_get_contents($this->temp->getFilePath('.phpstorm.meta.php'));

        self::assertStringContainsString("registerArgumentsSet('stewart_light_entity_ids', 'light.hall', 'light.porch_2');", $meta);
        self::assertStringContainsString("expectedArguments(\\Acme\\Home\\SwitchEntities::getEntity(), 0, argumentsSet('stewart_switch_entity_ids'));", $meta);
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

    /** @param list<string> $include */
    private function writeFiles(Snapshot $snapshot, array $include = ['*']): GenerationReport
    {
        return new GenerationRun(
            GoldenSnapshot::resolveGenerator(),
            new GenerationTarget('Acme\Home', $this->temp->path),
            new GenerationOptions(EntityInclusionRules::fromPatterns($include, ['light.debug_*'])),
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
