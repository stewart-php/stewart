<?php

declare(strict_types=1);

namespace Stewart\Codegen;

use Stewart\Codegen\Attribute\AttributeFilter;
use Stewart\Codegen\Attribute\Collection\AttributeFilterCollection;
use Stewart\Codegen\Entity\EntityFilter;

final readonly class GenerationOptions
{
    public EntityFilter $filter;

    private AttributeFilterCollection $attributes;

    /** @param array<string, string> $renames */
    public function __construct(
        ?EntityFilter $filter = null,
        public array $renames = [],
        ?AttributeFilterCollection $attributes = null,
    ) {
        $this->filter = $filter ?? EntityFilter::allowingEverything();
        $this->attributes = $attributes ?? AttributeFilterCollection::empty();
    }

    public function attributesFor(string $domain): AttributeFilter
    {
        return $this->attributes->find($domain) ?? new AttributeFilter($domain);
    }
}
