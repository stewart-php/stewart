<?php

declare(strict_types=1);

namespace Stewart\Codegen\Action;

final readonly class ActionDefinition
{
    /** @param list<ActionField> $fields */
    public function __construct(
        public string $domain,
        public string $name,
        public ?string $title,
        public ?string $description,
        public array $fields,
        public ActionTargetSpec $target,
        public bool $returnsResponse,
    ) {}

    public function belongsOnHandle(string $domain): bool
    {
        return $this->domain === $domain && $this->target->acceptsDomain($domain);
    }

}
