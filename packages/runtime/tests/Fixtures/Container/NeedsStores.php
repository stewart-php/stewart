<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\App;
use Stewart\Contracts\Store\ReadableStore;
use Stewart\Contracts\Store\Store;

final readonly class NeedsStores implements App
{
    public function __construct(
        public Store $store,
        public ReadableStore $view,
    ) {}

    public function initialize(): void {}

    public function dispose(): void {}
}
