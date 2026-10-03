<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Selector\SelectorInterface;

final class TestCodeSelector
{
    private const string TEST_DIRECTORY = '#/tests/#';

    private function __construct() {}

    public static function selectTestCode(): SelectorInterface
    {
        return Selector::AnyOf(
            Selector::withFilepath(self::TEST_DIRECTORY, true),
            Selector::inNamespace('Stewart\Testing'),
        );
    }
}
