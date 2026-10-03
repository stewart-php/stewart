<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Ipc;

use Stewart\Runtime\Ipc\Message\WorkerMessage;

final readonly class UntaggedMessage implements WorkerMessage {}
