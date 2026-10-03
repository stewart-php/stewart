<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Generated;

final class GeneratedSet
{
    public const string NAMESPACE = 'Stewart\Runtime\Tests\Fixtures\Generated\Code';

    public const array IGNORED_ENTITY_PATTERNS = ['light.debug_*'];

    private function __construct() {}

    public static function getSnapshotPath(): string
    {
        return __DIR__ . '/snapshot.json';
    }

    public static function getDirectory(): string
    {
        return __DIR__ . '/Code';
    }
}
