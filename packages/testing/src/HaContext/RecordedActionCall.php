<?php

declare(strict_types=1);

namespace Stewart\Testing\HaContext;

use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\State\EventContext;

final readonly class RecordedActionCall
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $domain,
        public string $action,
        public array $data,
        public ?ActionTarget $target,
        public bool $returnsResponse,
        public EventContext $context,
    ) {}

    public function getActionName(): string
    {
        return $this->domain . '.' . $this->action;
    }

    /** @return list<string> */
    public function listTargetedEntityIds(): array
    {
        return $this->target === null ? [] : array_map(strval(...), $this->target->entityIds);
    }
}
