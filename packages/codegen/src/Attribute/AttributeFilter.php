<?php

declare(strict_types=1);

namespace Stewart\Codegen\Attribute;

final readonly class AttributeFilter
{
    public function __construct(
        public string $domain,
        public KeyPatterns $include = new KeyPatterns(),
        public KeyPatterns $exclude = new KeyPatterns(),
    ) {}

    public function excludes(string $key): bool
    {
        return $this->exclude->matches($key);
    }

    public function includes(string $key): bool
    {
        return $this->include->matches($key);
    }
}
