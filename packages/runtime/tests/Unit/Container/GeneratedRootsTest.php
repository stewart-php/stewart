<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Unit\Container;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Runtime\App\GeneratedRoots;
use Stewart\Runtime\Exception\AppError;
use Stewart\Runtime\Tests\Fixtures\Generated\Code\Actions;
use Stewart\Runtime\Tests\Fixtures\Generated\Code\Entities;
use Stewart\Runtime\Tests\Fixtures\Generated\Code\Manifest;
use Stewart\Runtime\Tests\Fixtures\Generated\GeneratedSet;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(GeneratedRoots::class)]
final class GeneratedRootsTest extends TestCase
{
    use AssertsReason;

    public function testGeneratedNamespaceIsFoundByItsTwoRoots(): void
    {
        $roots = GeneratedRoots::fromNamespace(GeneratedSet::NAMESPACE);

        self::assertNotNull($roots);
        self::assertSame(Entities::class, $roots->entitiesClass);
        self::assertSame(Actions::class, $roots->actionsClass);
        self::assertSame(Manifest::class, $roots->manifestClass);
    }

    public function testLeadingBackslashIsNotANewNamespace(): void
    {
        self::assertEquals(
            GeneratedRoots::fromNamespace(GeneratedSet::NAMESPACE),
            GeneratedRoots::fromNamespace('\\' . GeneratedSet::NAMESPACE),
        );
    }

    public function testTreeFromOlderMajorIsRefused(): void
    {
        $this->assertThrowsReason(AppError::GeneratedCodeFromOlderMajor, static fn() => GeneratedRoots::fromNamespace(GeneratedSet::OLDER_MAJOR_NAMESPACE));
    }

    public function testNothingGeneratedIsNotAFailure(): void
    {
        self::assertNull(GeneratedRoots::fromNamespace('Stewart\\Runtime\\Tests\\Fixtures\\Generated\\Nowhere'));
    }
}
