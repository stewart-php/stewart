<?php

declare(strict_types=1);

namespace Stewart\Testing\HaContext;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\History\HistoryDetail;
use Stewart\Contracts\History\HistoryWindow;

final readonly class RecordedHistoryQuery
{
    public function __construct(
        public EntityId $entityId,
        public HistoryWindow $window,
        public HistoryDetail $detail,
    ) {}
}
