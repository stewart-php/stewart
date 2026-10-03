<?php

declare(strict_types=1);

namespace Stewart\Runtime\Schedule;

use Stewart\Runtime\Model\ResourceScope;

final readonly class ScheduleOrigin
{
    public function __construct(
        public ResourceScope $scope,
        public string $taskId,
        public string $description,
    ) {}

    public function label(): string
    {
        return \sprintf('schedule %s (%s)', $this->taskId, $this->description);
    }
}
