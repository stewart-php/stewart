<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\History\HistoryDetail;
use Stewart\Contracts\History\HistoryWindow;

final readonly class GetHistoryDuringPeriod implements HaCommand
{
    public function __construct(
        public EntityId $entityId,
        public HistoryWindow $window,
        public HistoryDetail $detail = HistoryDetail::StateChanges,
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
            'significant_changes_only' => !$this->detail->includesAttributeOnlyChanges(),
            'minimal_response' => !$this->detail->includesAttributes(),
            'no_attributes' => !$this->detail->includesAttributes(),
        ];
    }
}
