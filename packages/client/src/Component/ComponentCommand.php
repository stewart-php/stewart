<?php

declare(strict_types=1);

namespace Stewart\Client\Component;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Contracts\State\EventContext;

final readonly class ComponentCommand implements ComponentSessionEvent
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $commandId,
        public AppId $appId,
        public ExposedEntityKey $key,
        public ComponentCommandAction $action,
        public array $data,
        public EventContext $context,
    ) {}
}
