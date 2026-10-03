<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Stewart\Runtime\App\Collection\DiscoveredAppCollection;
use Stewart\Runtime\App\Collection\UnloadableAppFileCollection;
use Stewart\Runtime\App\Collection\UnmarkedAppCollection;

final readonly class DiscoveryResult
{
    public function __construct(
        public DiscoveredAppCollection $apps,
        public UnmarkedAppCollection $unmarked,
        public UnloadableAppFileCollection $unloadable,
    ) {}

    public static function createEmpty(): self
    {
        return new self(DiscoveredAppCollection::empty(), UnmarkedAppCollection::empty(), UnloadableAppFileCollection::empty());
    }
}
