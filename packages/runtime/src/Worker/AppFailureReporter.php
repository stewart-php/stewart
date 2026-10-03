<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Runtime\Ipc\Message\AppFailed;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Model\ExceptionDetails;
use Stewart\Runtime\Model\ResourceScope;
use Throwable;

final readonly class AppFailureReporter
{
    public function __construct(
        private Transport $transport,
        private StderrFallback $stderr,
        private AppActivityCounters $activityCounters,
        private HandlerFailureSampler $sampler,
    ) {}

    public function report(ResourceScope $scope, AppFailurePhase $phase, Throwable $error, ?string $origin = null): void
    {
        $this->activityCounters->findOrCreateActivityForScope($scope)->recordFailure();
        $occurrence = $this->sampler->recordFailure($scope, $phase, $origin);

        if (!$this->sampler->isSampled($occurrence)) {
            return;
        }

        try {
            $this->transport->send(new AppFailed(
                scope: $scope,
                phase: $phase,
                class: $error::class,
                message: $error->getMessage(),
                trace: $error->getTraceAsString(),
                origin: $origin,
                occurrence: $occurrence,
                details: ExceptionDetails::fromThrowable($error),
            ));
        } catch (Throwable) {
            // The channel is gone; stderr still records why the app failed.
            $this->stderr->writeLine($scope, \sprintf('%s %s: %s', $origin ?? $phase->value, $error::class, $error->getMessage()));
        }
    }
}
