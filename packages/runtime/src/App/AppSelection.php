<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;

final readonly class AppSelection
{
    public function __construct(
        public ?AppIdCollection $onlyIds = null,
        public ?AppIdCollection $tracedIds = null,
    ) {}

    public function admitsApp(AppId $appId, bool $enabled): bool
    {
        return $this->onlyIds === null ? $enabled : $this->onlyIds->containsId($appId);
    }

    public function tracesApp(AppId $appId): bool
    {
        return $this->tracedIds?->containsId($appId) ?? false;
    }
}
