<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Runtime\Exception\ConfigurationException;

final readonly class DomainAttributesConfig
{
    /**
     * @param list<string> $include
     * @param list<string> $exclude
     */
    public function __construct(
        public string $domain,
        public array $include,
        public array $exclude,
    ) {}

    /** @throws ConfigurationException */
    public static function fromSection(ConfigSection $attributes, string $domain): self
    {
        return new self(
            domain: $domain,
            include: $attributes->readStringList('include'),
            exclude: $attributes->readStringList('exclude'),
        );
    }
}
