<?php

declare(strict_types=1);

namespace Stewart\Codegen\Attribute;

final readonly class AttributeSelection
{
    /**
     * @param array<string, AttributeKind> $typed
     * @param list<UnseenAttribute> $unseen
     */
    public function __construct(
        public array $typed,
        public array $unseen,
    ) {}
}
