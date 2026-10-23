<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Rule;

use PHPStan\Type\Type;
use Stewart\Contracts\Entity\EntityId;

final readonly class EntityIdLiterals
{
    // Globs, times of day and other strings that are no entity ID are left alone.
    /** @return list<string> */
    public function listEntityIds(Type $type): array
    {
        $entityIds = [];

        foreach ($type->getConstantStrings() as $string) {
            if (EntityId::tryFromString($string->getValue()) !== null) {
                $entityIds[] = $string->getValue();
            }
        }

        return $entityIds;
    }
}
