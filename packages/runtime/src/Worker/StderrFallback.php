<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;

final readonly class StderrFallback
{
    public function __construct(
        private WorkerId $workerId,
        private string $fallbackStream = 'php://stderr',
    ) {}

    public function writeLine(ResourceScope $scope, string $text): void
    {
        file_put_contents($this->fallbackStream, \sprintf("[worker %d][%s] %s\n", $this->workerId->value, $scope, $text), \FILE_APPEND);
    }
}
