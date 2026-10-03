<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\Store\PeerStore;
use Stewart\Contracts\Store\ReadableStore;
use Stewart\Contracts\Store\Store;

final readonly class SharedStoreConsumer
{
    public function __construct(
        public Store $store,
        public ReadableStore $view,
        #[PeerStore('demo')]
        public ReadableStore $demo,
    ) {}
}
