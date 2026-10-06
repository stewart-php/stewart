<?php

declare(strict_types=1);

namespace Stewart\Store;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StoreException;

final readonly class Keyspace
{
    // Glob-safe for prefix scans; \z rejects a trailing newline.
    private const string KEY_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/';

    private function __construct(public string $prefix) {}

    public static function forApp(StorePrefix $prefix, AppId $appId): self
    {
        return new self($prefix->value . ':app:' . $appId->value . ':');
    }

    public static function forGlobalScope(StorePrefix $prefix): self
    {
        return new self($prefix->value . ':global:');
    }

    public static function forRuntime(StorePrefix $prefix): self
    {
        return new self($prefix->value . ':runtime:');
    }

    /** @throws StoreException */
    public function buildFullKey(string $key): string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw StoreException::keyInvalid($key);
        }

        return $this->prefix . $key;
    }

    public function extractRelativeKey(string $fullKey): string
    {
        return substr($fullKey, \strlen($this->prefix));
    }
}
