<?php

declare(strict_types=1);

namespace Stewart\Store;

use SensitiveParameter;
use Stewart\Store\Exception\StoreSetupException;
use Stewart\Support\Url\UrlRedactor;
use Stringable;

final readonly class StoreDsn implements Stringable
{
    private const string SCHEME_PATTERN = '~^([a-z][a-z0-9+.-]*)://~i';

    private function __construct(
        #[SensitiveParameter]
        private string $dsn,
        public string $scheme,
        private string $redacted,
    ) {}

    /** @throws StoreSetupException */
    public static function parse(#[SensitiveParameter] string $dsn): self
    {
        if (preg_match(self::SCHEME_PATTERN, $dsn, $matches) !== 1) {
            throw StoreSetupException::dsnInvalid($dsn);
        }

        return new self($dsn, strtolower($matches[1]), UrlRedactor::redactCredentials($dsn));
    }

    public function reveal(): string
    {
        return $this->dsn;
    }

    public function __toString(): string
    {
        return $this->redacted;
    }
}
