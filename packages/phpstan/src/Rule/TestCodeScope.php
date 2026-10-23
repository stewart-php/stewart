<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Rule;

use PHPStan\Analyser\Scope;

// Tests seed made-up entities into the harness, so code inside a test case is not checked.
final readonly class TestCodeScope
{
    private const string TEST_CASE = 'PHPUnit\\Framework\\TestCase';

    public function isInTestCode(Scope $scope): bool
    {
        return $scope->getClassReflection()?->is(self::TEST_CASE) === true;
    }
}
