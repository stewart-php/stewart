<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Runtime\Model\CorrelationId;
use Stewart\Runtime\Model\WorkerId;

final class CorrelationIdSequence
{
    private int $issued = 0;

    public function __construct(private readonly WorkerId $workerId) {}

    public function issueNext(): CorrelationId
    {
        return CorrelationId::fromString($this->workerId->value . ':' . $this->issued++);
    }
}
