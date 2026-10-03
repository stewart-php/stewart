<?php

declare(strict_types=1);

namespace Stewart\Codegen\Attribute;

use Stewart\Codegen\Model\GenerationWarning;

final readonly class UnseenAttribute implements GenerationWarning
{
    public function __construct(
        public string $domain,
        public string $key,
    ) {}

    public function describeWarning(): string
    {
        return \sprintf('No %s entity reports the included attribute %s.', $this->domain, $this->key);
    }
}
