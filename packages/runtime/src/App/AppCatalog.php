<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;

final readonly class AppCatalog
{
    public function __construct(
        public AppDefinitionCollection $enabled,
        public AppIdCollection $knownIds,
        public AppIdCollection $unmatchedOverrideIds,
    ) {}
}
