<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Container;

use Stewart\Contracts\App;
use Stewart\Contracts\Store\PeerStore;
use Stewart\Contracts\Store\ReadableStore;

final readonly class NeedsUnknownPeer implements App
{
    public function __construct(
        #[PeerStore('boilr')]
        public ReadableStore $peer,
    ) {}

    public function initialize(): void {}

    public function dispose(): void {}
}
