<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\App;
use Stewart\Contracts\Store\PeerStore;
use Stewart\Contracts\Store\Store;

final readonly class NeedsWritablePeer implements App
{
    public function __construct(
        #[PeerStore('boiler')]
        public Store $peer,
    ) {}

    public function initialize(): void {}

    public function dispose(): void {}
}
