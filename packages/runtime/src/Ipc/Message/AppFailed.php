<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Runtime\Lifecycle\AppFailurePhase;
use Stewart\Runtime\Model\ExceptionDetails;
use Stewart\Runtime\Model\ResourceScope;

#[IpcMessage(tag: 'app_failed')]
final readonly class AppFailed implements WorkerMessage
{
    public function __construct(
        public ResourceScope $scope,
        public AppFailurePhase $phase,
        public string $class,
        public string $message,
        public string $trace,
        public ?string $origin,
        public int $occurrence,
        public ?ExceptionDetails $details,
    ) {}
}
