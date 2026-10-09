<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Exposure;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exposure\Command\ExposedCommand;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Runtime\Model\WorkerId;

final readonly class ExposedEntityCommand
{
    public function __construct(
        public string $commandId,
        public WorkerId $owner,
        public AppId $appId,
        public ExposedEntityKey $key,
        public ExposedCommand $command,
    ) {}
}
