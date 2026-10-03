<?php

declare(strict_types=1);

namespace Stewart\Codegen\Attribute;

use Stewart\Codegen\Attribute\Collection\UnseenAttributeCollection;

final readonly class AttributeSelection
{
    /**
     * @param array<string, AttributeKind> $typed
     */
    public function __construct(
        public array $typed,
        public UnseenAttributeCollection $unseen,
    ) {}
}
