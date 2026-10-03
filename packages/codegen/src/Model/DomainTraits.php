<?php

declare(strict_types=1);

namespace Stewart\Codegen\Model;

final readonly class DomainTraits
{
    public function __construct(
        public bool $onOff,
        public bool $numeric,
    ) {}
}
