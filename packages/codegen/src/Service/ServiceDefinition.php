<?php

declare(strict_types=1);

namespace Stewart\Codegen\Service;

final readonly class ServiceDefinition
{
    /** @param list<ServiceField> $fields */
    public function __construct(
        public string $domain,
        public string $name,
        public ?string $title,
        public ?string $description,
        public array $fields,
        public ServiceTargetSpec $target,
        public bool $returnsResponse,
    ) {}

    public function belongsOnHandle(string $domain): bool
    {
        return $this->domain === $domain && $this->target->acceptsDomain($domain);
    }

}
