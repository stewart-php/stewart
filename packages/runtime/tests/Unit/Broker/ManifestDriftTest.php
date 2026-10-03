<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Broker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\Broker\ManifestDrift;

#[CoversClass(ManifestDrift::class)]
final class ManifestDriftTest extends TestCase
{
    public function testMatchingSetsDoNotDrift(): void
    {
        self::assertTrue(ManifestDrift::calculateBetween(['light.hall', 'light.kitchen'], ['light.kitchen', 'light.hall'])->isEmpty());
    }

    public function testReportsBothDirectionsSorted(): void
    {
        $drift = ManifestDrift::calculateBetween(['light.hall', 'light.old'], ['light.new', 'light.hall', 'light.attic']);

        self::assertSame(['light.attic', 'light.new'], $drift->added);
        self::assertSame(['light.old'], $drift->removed);
        self::assertFalse($drift->isEmpty());
    }

    public function testEntityLeftOutOnPurposeIsNotAnAddition(): void
    {
        $drift = ManifestDrift::calculateBetween(['light.hall'], ['light.hall', 'light.garage'], ['light.garage']);

        self::assertTrue($drift->isEmpty());
    }
}
