<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\App;
use Stewart\Contracts\Store\GlobalStore;
use Stewart\Contracts\Store\PeerStore;
use Stewart\Contracts\Store\ReadableStore;
use Stewart\Contracts\Store\Store;

final readonly class NeedsAttributedStores implements App
{
    public function __construct(
        public Store $store,
        #[GlobalStore]
        public Store $shared,
        #[GlobalStore]
        public ReadableStore $sharedView,
        #[PeerStore('boiler')]
        public ReadableStore $peer,
    ) {}

    public function initialize(): void {}

    public function dispose(): void {}
}
