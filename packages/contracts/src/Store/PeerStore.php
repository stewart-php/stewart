<?php

declare(strict_types=1);

namespace Stewart\Contracts\Store;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class PeerStore
{
    public function __construct(public string $appId) {}
}
