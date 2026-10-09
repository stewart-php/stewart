<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

use Stewart\Runtime\Model\ResourceScope;

#[IpcMessage(tag: 'exposures_released')]
final readonly class ExposuresReleased implements WorkerMessage
{
    public function __construct(public ResourceScope $scope) {}
}
