<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use SensitiveParameter;
use Stewart\Runtime\Exception\ConfigurationException;
use Stewart\Support\Url\UrlRedactor;
use Stringable;

final readonly class MqttServerUrl implements Stringable
{
    private const string PLAIN_SCHEME = 'mqtt';

    private const string TLS_SCHEME = 'mqtts';

    private const int PLAIN_DEFAULT_PORT = 1883;

    private const int TLS_DEFAULT_PORT = 8883;

    private function __construct(
        public bool $usesTls,
        public string $host,
        public int $port,
        public ?string $username,
        #[SensitiveParameter]
        private ?string $password,
        private string $redacted,
    ) {}

    /** @throws ConfigurationException */
    public static function parse(#[SensitiveParameter] string $url): self
    {
        $redacted = UrlRedactor::redactCredentials($url);
        $parts = parse_url($url);
        $scheme = \is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';

        if (!\is_array($parts) || !isset($parts['host']) || !\in_array($scheme, [self::PLAIN_SCHEME, self::TLS_SCHEME], true)) {
            throw ConfigurationException::valueInvalid($redacted, 'an MQTT server URL; use mqtt://host:port or mqtts://host:port');
        }

        $usesTls = $scheme === self::TLS_SCHEME;

        return new self(
            usesTls: $usesTls,
            host: $parts['host'],
            port: $parts['port'] ?? ($usesTls ? self::TLS_DEFAULT_PORT : self::PLAIN_DEFAULT_PORT),
            username: isset($parts['user']) ? rawurldecode($parts['user']) : null,
            password: isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            redacted: $redacted,
        );
    }

    public function revealPassword(): ?string
    {
        return $this->password;
    }

    public function toSocketUri(): string
    {
        return 'tcp://' . $this->host . ':' . $this->port;
    }

    public function __toString(): string
    {
        return $this->redacted;
    }
}
