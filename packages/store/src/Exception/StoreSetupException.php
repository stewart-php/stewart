<?php

declare(strict_types=1);

namespace Stewart\Store\Exception;

use SensitiveParameter;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StewartException;

/** @extends StewartException<StoreSetupError> */
final class StoreSetupException extends StewartException
{
    // The DSN may hold a password, so only its length reaches the message.
    public static function dsnInvalid(#[SensitiveParameter] string $dsn): self
    {
        return self::createForReason(StoreSetupError::DsnInvalid, ['length' => \strlen($dsn)]);
    }

    /** @param list<string> $supported */
    public static function schemeUnsupported(string $scheme, array $supported): self
    {
        return self::createForReason(StoreSetupError::SchemeUnsupported, ['scheme' => $scheme, 'supported' => $supported]);
    }

    public static function unknownPeerApp(AppId $appId, ?string $suggestion): self
    {
        return self::createForReasonWithAppendedText(
            StoreSetupError::UnknownPeerApp,
            ['appId' => $appId->value, 'suggestion' => $suggestion],
            $suggestion === null ? null : \sprintf('Did you mean "%s"?', $suggestion),
        );
    }

    public static function prefixInvalid(string $prefix): self
    {
        return self::createForReason(StoreSetupError::PrefixInvalid, ['prefix' => $prefix]);
    }
}
