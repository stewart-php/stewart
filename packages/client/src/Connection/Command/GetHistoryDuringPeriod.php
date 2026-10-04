<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\History\HistoryWindow;

final readonly class GetHistoryDuringPeriod implements HaCommand
{
    public function __construct(
        public EntityId $entityId,
        public HistoryWindow $window,
        public bool $includeAttributes = false,
    ) {}

    public function type(): string
    {
        return 'history/history_during_period';
    }

    public function describe(): string
    {
        return \sprintf('%s %s', $this->type(), $this->entityId->value);
    }

    public function toMessage(): array
    {
        return [
            'type' => $this->type(),
            'start_time' => $this->window->startsAt->toIso8601(),
            'end_time' => $this->window->endsAt->toIso8601(),
            'entity_ids' => [$this->entityId->value],
            'include_start_time_state' => true,
            'significant_changes_only' => true,
            'minimal_response' => !$this->includeAttributes,
            'no_attributes' => !$this->includeAttributes,
        ];
    }
}
