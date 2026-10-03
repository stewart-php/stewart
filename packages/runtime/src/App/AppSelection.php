<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;

final readonly class AppSelection
{
    public function __construct(public ?AppIdCollection $onlyIds = null) {}

    public function admitsApp(AppId $appId, bool $enabled): bool
    {
        return $this->onlyIds === null ? $enabled : $this->onlyIds->containsId($appId);
    }
}
