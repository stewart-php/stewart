<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Logging\EveryNthOccurrence;
use Stewart\Runtime\Model\ResourceScope;

final class HandlerFailureSampler
{
    private const int ONE_OFF_FAILURE_OCCURRENCE = 1;

    /** @var array<string, int> */
    private array $occurrencesByOrigin = [];

    private readonly EveryNthOccurrence $samples;

    public function __construct()
    {
        $this->samples = EveryNthOccurrence::forRepeatedWarnings();
    }

    public function recordFailure(ResourceScope $scope, AppFailurePhase $phase, ?string $origin): int
    {
        if ($phase !== AppFailurePhase::Handler) {
            return self::ONE_OFF_FAILURE_OCCURRENCE;
        }

        $key = $scope->wireValue() . "\0" . $origin;

        return $this->occurrencesByOrigin[$key] = ($this->occurrencesByOrigin[$key] ?? 0) + 1;
    }

    public function isSampled(int $occurrence): bool
    {
        return $this->samples->includesOccurrence($occurrence);
    }
}
