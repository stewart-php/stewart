<?php

declare(strict_types=1);

namespace Stewart\Client\Connection;

use SensitiveParameter;
use Stewart\Client\Exception\HaClientException;
use Stewart\Support\Url\UrlRedactor;
use Stringable;

final readonly class HomeAssistantUrl implements Stringable
{
    private const string SCHEME = '~\A([a-z][a-z0-9+.-]*)://~i';

    private const array WEBSOCKET_SCHEMES = ['http' => 'ws', 'https' => 'wss', 'ws' => 'ws', 'wss' => 'wss'];

    private const string DEFAULT_SCHEME = 'ws';

    private const string WEBSOCKET_PATH = '/api/websocket';

    private const string WEBSOCKET_PATH_ENDING = '/websocket';

    private function __construct(
        #[SensitiveParameter]
        private string $url,
        private string $redacted,
    ) {}

    /** @throws HaClientException */
    public static function parse(#[SensitiveParameter] string $address): self
    {
        return self::tryParse($address) ?? throw HaClientException::urlInvalid(UrlRedactor::redactCredentials(self::prefixScheme(trim($address))));
    }

    public static function tryParse(#[SensitiveParameter] string $address): ?self
    {
        $address = trim($address);

        if (preg_match(self::SCHEME, $address, $match) === 1) {
            $scheme = self::WEBSOCKET_SCHEMES[strtolower($match[1])] ?? null;

            if ($scheme === null) {
                return null;
            }

            $address = $scheme . '://' . substr($address, \strlen($match[0]));
        }

        $url = rtrim(self::prefixScheme($address), '/');
        $parts = parse_url($url);

        if (!\is_array($parts) || ($parts['host'] ?? '') === '') {
            return null;
        }

        $url = self::appendWebsocketPath($url, $parts['path'] ?? '', $parts['query'] ?? null, $parts['fragment'] ?? null);

        return new self($url, UrlRedactor::redactCredentials($url));
    }

    public function reveal(): string
    {
        return $this->url;
    }

    public function __toString(): string
    {
        return $this->redacted;
    }

    private static function prefixScheme(string $address): string
    {
        return preg_match(self::SCHEME, $address) === 1 ? $address : self::DEFAULT_SCHEME . '://' . $address;
    }

    // Appended to the path: appending to the whole URL would put it after the query string.
    private static function appendWebsocketPath(string $url, string $path, ?string $query, ?string $fragment): string
    {
        if (str_ends_with(rtrim($path, '/'), self::WEBSOCKET_PATH_ENDING)) {
            return $url;
        }

        $tail = ($query === null ? '' : '?' . $query) . ($fragment === null ? '' : '#' . $fragment);
        $head = $tail === '' ? $url : substr($url, 0, \strlen($url) - \strlen($tail));

        return rtrim($head, '/') . self::WEBSOCKET_PATH . $tail;
    }
}
