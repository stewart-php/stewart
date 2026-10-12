<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

/** @extends StewartException<IdentifierError> */
final class IdentifierException extends StewartException
{
    public static function entityNotGenerated(string $entityId, string $domain): self
    {
        return self::createForReason(IdentifierError::EntityNotGenerated, entityId: $entityId, domain: $domain);
    }
}
