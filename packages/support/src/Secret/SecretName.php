<?php

declare(strict_types=1);

namespace Stewart\Support\Secret;

/** @internal */
final class SecretName
{
    private const string SECRET = 'token|secret|password|passwd|(?<![a-z])pass(?![a-z])|api_?key';

    private const string CREDENTIAL_PARAMETER_EXTRAS = 'auth|signature|sig';

    private function __construct() {}

    public static function looksSecret(string $name): bool
    {
        return preg_match('/' . self::SECRET . '/i', $name) === 1;
    }

    public static function looksLikeCredentialParameter(string $name): bool
    {
        return preg_match('/' . self::SECRET . '|' . self::CREDENTIAL_PARAMETER_EXTRAS . '/i', $name) === 1;
    }
}
