<?php

declare(strict_types=1);

namespace Stewart\Codegen\Tests\Fixtures\Php;

use Stewart\Codegen\Php\MemberScope;
use Stewart\Codegen\Php\ReservesMemberNames;

final readonly class FixedMemberReserver implements ReservesMemberNames
{
    /** @param list<string> $names */
    public function __construct(
        private MemberScope $scope,
        private array $names,
    ) {}

    public function listReservedMemberNames(MemberScope $scope): array
    {
        return $scope === $this->scope ? $this->names : [];
    }
}
