<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Exposure;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\Exposure\ExposedEntitySnapshot;
use Stewart\Runtime\Model\WorkerId;

final readonly class ExposedEntitySync
{
    public function __construct(
        public WorkerId $owner,
        public AppId $appId,
        public ExposedEntityKey $key,
        public ExposedEntitySnapshot $snapshot,
    ) {}
}
