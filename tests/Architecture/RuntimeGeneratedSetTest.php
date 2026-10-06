<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Stewart\Codegen\Entity\EntityInclusionRules;
use Stewart\Codegen\GenerationOptions;
use Stewart\Codegen\GenerationTarget;
use Stewart\Codegen\Tests\Fixtures\GeneratedTreeComparison;
use Stewart\Codegen\Tests\Fixtures\GenerationRun;
use Stewart\Codegen\Tests\Fixtures\GoldenSnapshot;
use Stewart\Runtime\Tests\Fixtures\Generated\GeneratedSet;

#[CoversNothing]
final class RuntimeGeneratedSetTest extends TestCase
{
    public function testCommittedSetMatchesGeneratorOutput(): void
    {
        $generation = new GenerationRun(
            GoldenSnapshot::resolveGenerator(),
            new GenerationTarget(GeneratedSet::NAMESPACE, GeneratedSet::getDirectory()),
            new GenerationOptions(EntityInclusionRules::fromPatterns(['*'], GeneratedSet::IGNORED_ENTITY_PATTERNS)),
        );

        new GeneratedTreeComparison($generation)
            ->assertCommittedTreeMatches(GoldenSnapshot::readSnapshotFile(GeneratedSet::getSnapshotPath()));
    }
}
