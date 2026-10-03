<?php

declare(strict_types=1);

namespace Stewart\Codegen\Php;

interface ReservesMemberNames
{
    /** @return list<string> */
    public function listReservedMemberNames(MemberScope $scope): array;
}
