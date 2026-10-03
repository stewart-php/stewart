<?php

declare(strict_types=1);

namespace Stewart\Testing\HaContext;

use Stewart\Contracts\Service\ServiceTarget;

final readonly class RecordedServiceCall
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $domain,
        public string $service,
        public array $data,
        public ?ServiceTarget $target,
        public bool $returnsResponse,
    ) {}

    public function getServiceName(): string
    {
        return $this->domain . '.' . $this->service;
    }

    /** @return list<string> */
    public function listTargetedEntityIds(): array
    {
        return $this->target === null ? [] : array_map(strval(...), $this->target->entityIds);
    }
}
