<?php

declare(strict_types=1);

namespace Stewart\Client\Component;

use Stewart\Client\Component\Collection\ExposedEntityAddressCollection;
use Stewart\Contracts\App\Collection\AppIdCollection;

final readonly class ExposedEntityReconcile
{
    public function __construct(
        public ComponentInstance $instance,
        public ExposedEntityAddressCollection $keptAddresses,
        public AppIdCollection $keptApps,
    ) {}
}
