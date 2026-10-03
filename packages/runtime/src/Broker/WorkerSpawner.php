<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Amp\Cancellation;
use Stewart\Runtime\Model\WorkerId;

interface WorkerSpawner
{
    public function spawn(WorkerId $workerId, Cancellation $deadline): WorkerProcess;
}
