<?php

declare(strict_types=1);

namespace Stewart\Support\Url;

use Stewart\Support\Secret\SecretName;

/** @internal */
final class UrlRedactor
{
    private const string MASK = '***';

    private const string URL_PARTS = '#^([a-z][a-z0-9+.-]*)://([^/?\#]*)([^?\#]*)(?:\?([^\#]*))?(?:\#(.*))?$#is';

    private function __construct() {}

    public static function redactCredentials(string $value): string
    {
        // parse_url() rejects host-less URLs such as unix:///run/valkey.sock, so the parts are split here.
        if (preg_match(self::URL_PARTS, $value, $parts, \PREG_UNMATCHED_AS_NULL) !== 1) {
            return $value;
        }

        [, $scheme, $authority, $path, $query, $fragment] = array_pad($parts, 6, null);

        return $scheme . '://' . self::maskUserinfo((string) $authority) . $path
            . ($query === null ? '' : '?' . self::redactCredentialQueryParameters($query))
            . ($fragment === null ? '' : '#' . self::MASK);
    }

    public static function looksLikeUrl(string $value): bool
    {
        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1;
    }

    private static function maskUserinfo(string $authority): string
    {
        $at = strrpos($authority, '@');

        if ($at === false) {
            return $authority;
        }

        $userinfo = str_contains(substr($authority, 0, $at), ':') ? self::MASK . ':' . self::MASK : self::MASK;

        return $userinfo . '@' . substr($authority, $at + 1);
    }

    private static function redactCredentialQueryParameters(string $query): string
    {
        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $split = explode('=', $pair, 2);
            $name = $split[0];

            $pairs[] = \count($split) === 1 || !SecretName::looksLikeCredentialParameter($name)
                ? $pair
                : $name . '=' . self::MASK;
        }

        return implode('&', $pairs);
    }
}
