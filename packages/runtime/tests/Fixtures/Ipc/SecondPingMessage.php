<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Ipc;

use Stewart\Runtime\Ipc\Message\IpcMessage;
use Stewart\Runtime\Ipc\Message\WorkerMessage;

#[IpcMessage(tag: 'ping')]
final readonly class SecondPingMessage implements WorkerMessage {}
