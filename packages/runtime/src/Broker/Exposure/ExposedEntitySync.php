<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Exposure;

use Stewart\Client\Component\ExposedEntitySnapshot;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exposure\ExposedEntityKey;
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
