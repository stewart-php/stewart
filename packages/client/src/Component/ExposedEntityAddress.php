<?php

declare(strict_types=1);

namespace Stewart\Client\Component;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exposure\ExposedEntityKey;

final readonly class ExposedEntityAddress
{
    public function __construct(
        public ComponentInstance $instance,
        public AppId $appId,
        public ExposedEntityKey $key,
    ) {}

    /** @return array{instance: string, app: string, key: string} */
    public function toMessageFields(): array
    {
        return ['instance' => $this->instance->value, 'app' => $this->appId->value, 'key' => $this->key->value];
    }

    public function describe(): string
    {
        return \sprintf('%s/%s/%s', $this->instance, $this->appId, $this->key);
    }
}
