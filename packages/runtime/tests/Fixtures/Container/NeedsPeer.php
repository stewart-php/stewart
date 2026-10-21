<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\App;
use Stewart\Contracts\Store\PeerStore;
use Stewart\Contracts\Store\ReadableStore;

final readonly class NeedsPeer implements App
{
    public function __construct(
        #[PeerStore('boiler')]
        public ReadableStore $peer,
    ) {}

    public function initialize(): void {}
}
