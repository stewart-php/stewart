<?php

declare(strict_types=1);

namespace Stewart\Codegen\Service;

use Stewart\Codegen\Service\Collection\ServiceFieldCollection;

final readonly class ServiceDefinition
{
    public function __construct(
        public string $domain,
        public string $name,
        public ?string $title,
        public ?string $description,
        public ServiceFieldCollection $fields,
        public ServiceTargetSpec $target,
        public bool $returnsResponse,
    ) {}

    public function belongsOnHandle(string $domain): bool
    {
        return $this->domain === $domain && $this->target->acceptsDomain($domain);
    }

}
