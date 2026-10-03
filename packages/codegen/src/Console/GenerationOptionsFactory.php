<?php

declare(strict_types=1);

namespace Stewart\Codegen\Console;

use Stewart\Codegen\Attribute\AttributeFilter;
use Stewart\Codegen\Attribute\Collection\AttributeFilterCollection;
use Stewart\Codegen\Attribute\KeyPatterns;
use Stewart\Codegen\Entity\EntityFilter;
use Stewart\Codegen\GenerationOptions;
use Stewart\Runtime\Config\CodegenConfig;

final readonly class GenerationOptionsFactory
{
    public function createFromCodegenConfig(CodegenConfig $codegen): GenerationOptions
    {
        return new GenerationOptions(
            EntityFilter::fromPatterns($codegen->include, $codegen->exclude),
            $this->buildAttributeFilters($codegen),
        );
    }

    private function buildAttributeFilters(CodegenConfig $codegen): AttributeFilterCollection
    {
        $filters = [];

        foreach ($codegen->attributes as $domain) {
            $filters[] = new AttributeFilter($domain->domain, new KeyPatterns($domain->include), new KeyPatterns($domain->exclude));
        }

        return AttributeFilterCollection::keyedByDomain($filters);
    }
}
