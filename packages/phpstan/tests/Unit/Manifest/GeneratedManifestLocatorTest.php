<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Tests\Unit\Manifest;

use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Stewart\PHPStan\Manifest\GeneratedManifestLocator;

#[CoversClass(GeneratedManifestLocator::class)]
final class GeneratedManifestLocatorTest extends PHPStanTestCase
{
    private const string CURRENT = 'Stewart\PHPStan\Tests\Fixtures\Generated\\';

    private const string OLDER_MAJOR = 'Stewart\PHPStan\Tests\Fixtures\OlderMajor\\';

    private const string UNRELATED = 'Stewart\PHPStan\Tests\Fixtures\Analysed\\';

    public function testTheOneNamespaceWithGeneratedClassesNamesTheManifest(): void
    {
        self::assertSame(self::CURRENT . 'Manifest', self::createLocator()->findManifestClassAmong([self::UNRELATED, self::CURRENT]));
    }

    public function testATreeFromAnOlderMajorIsFoundWithoutLoadingIt(): void
    {
        self::assertSame(self::OLDER_MAJOR . 'Manifest', self::createLocator()->findManifestClassAmong([self::OLDER_MAJOR]));
    }

    public function testNoGeneratedClassesOrTwoTreesFindNothing(): void
    {
        self::assertNull(self::createLocator()->findManifestClassAmong([self::UNRELATED]));
        self::assertNull(self::createLocator()->findManifestClassAmong([self::CURRENT, self::OLDER_MAJOR]));
    }

    private static function createLocator(): GeneratedManifestLocator
    {
        return new GeneratedManifestLocator(self::createReflectionProvider());
    }
}
