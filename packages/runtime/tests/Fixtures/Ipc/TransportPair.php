<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Ipc;

use Stewart\Runtime\Ipc\Transport;

final readonly class TransportPair
{
    public function __construct(
        public Transport $broker,
        public Transport $worker,
    ) {}
}
