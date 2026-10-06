<?php

declare(strict_types=1);

namespace Stewart\Codegen;

use Stewart\Codegen\Attribute\AttributeFilter;
use Stewart\Codegen\Attribute\Collection\AttributeFilterCollection;
use Stewart\Codegen\Entity\EntityInclusionRules;

final readonly class GenerationOptions
{
    public EntityInclusionRules $inclusionRules;

    private AttributeFilterCollection $attributes;

    public function __construct(
        ?EntityInclusionRules $inclusionRules = null,
        ?AttributeFilterCollection $attributes = null,
    ) {
        $this->inclusionRules = $inclusionRules ?? EntityInclusionRules::allowingEverything();
        $this->attributes = $attributes ?? AttributeFilterCollection::empty();
    }

    public function attributesFor(string $domain): AttributeFilter
    {
        return $this->attributes->find($domain) ?? new AttributeFilter($domain);
    }
}
