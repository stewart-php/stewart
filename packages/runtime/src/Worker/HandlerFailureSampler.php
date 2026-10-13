<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Logging\EveryNthOccurrence;
use Stewart\Runtime\Model\ResourceScope;
use Throwable;

final class HandlerFailureSampler
{
    private const int ONE_OFF_FAILURE_OCCURRENCE = 1;

    private const string STEWART_NAMESPACE = 'Stewart\\';

    private const string VENDOR_DIRECTORY = '/vendor/';

    /** @var array<string, int> */
    private array $occurrencesByFailingLine = [];

    private readonly EveryNthOccurrence $samples;

    public function __construct()
    {
        $this->samples = EveryNthOccurrence::forRepeatedWarnings();
    }

    public function recordFailure(ResourceScope $scope, AppFailurePhase $phase, Throwable $error): int
    {
        if ($phase !== AppFailurePhase::Handler) {
            return self::ONE_OFF_FAILURE_OCCURRENCE;
        }

        // Origins carry task ids and moments, so only the failing line keeps the map bounded.
        $key = \sprintf("%s\0%s\0%s", $scope->wireValue(), $error::class, $this->locateFailingLine($error));

        return $this->occurrencesByFailingLine[$key] = ($this->occurrencesByFailingLine[$key] ?? 0) + 1;
    }

    public function isSampled(int $occurrence): bool
    {
        return $this->samples->includesOccurrence($occurrence);
    }

    // The first app line, so two app lines failing inside the same runtime call stay apart.
    private function locateFailingLine(Throwable $error): string
    {
        $file = $error->getFile();
        $line = $error->getLine();
        $firstLineOutsideFactories = null;

        foreach ($error->getTrace() as $caller) {
            $class = $caller['class'] ?? null;

            if ($this->isAppCode($file, $class)) {
                return $file . ':' . $line;
            }

            if ($firstLineOutsideFactories === null && $class !== null && !is_a($class, Throwable::class, true)) {
                $firstLineOutsideFactories = $file . ':' . $line;
            }

            $file = $caller['file'] ?? '';
            $line = $caller['line'] ?? 0;
        }

        return $firstLineOutsideFactories ?? $file . ':' . $line;
    }

    private function isAppCode(string $file, ?string $class): bool
    {
        return $class !== null && !str_starts_with($class, self::STEWART_NAMESPACE) && !str_contains($file, self::VENDOR_DIRECTORY);
    }
}
