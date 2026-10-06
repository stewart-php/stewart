<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

/** @extends StewartException<IdentifierError> */
final class IdentifierException extends StewartException
{
    public static function entityIdInvalid(string $entityId): self
    {
        return self::createForReason(IdentifierError::EntityIdInvalid, ['entityId' => $entityId]);
    }

    public static function appIdInvalid(string $appId): self
    {
        return self::createForReason(IdentifierError::AppIdInvalid, ['appId' => $appId]);
    }

    public static function entityNotGenerated(string $entityId, string $domain): self
    {
        return self::createForReason(IdentifierError::EntityNotGenerated, ['entityId' => $entityId, 'domain' => $domain]);
    }

    public static function registryIdEmpty(string $kind): self
    {
        return self::createForReason(IdentifierError::RegistryIdEmpty, ['kind' => $kind]);
    }
}
