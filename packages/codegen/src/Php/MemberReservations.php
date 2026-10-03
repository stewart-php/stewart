<?php

declare(strict_types=1);

namespace Stewart\Codegen\Php;

final readonly class MemberReservations
{
    /** @param iterable<ReservesMemberNames> $reservers */
    public function __construct(private iterable $reservers) {}

    public function createAllocatorFor(MemberScope $scope): IdentifierAllocator
    {
        $reserved = [];

        foreach ($this->reservers as $reserver) {
            $reserved = [...$reserved, ...$reserver->listReservedMemberNames($scope)];
        }

        return new IdentifierAllocator(array_values(array_unique($reserved)), $scope->getCollisionSuffix());
    }
}
