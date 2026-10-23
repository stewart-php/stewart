<?php

declare(strict_types=1);

namespace Stewart\Codegen\Action\Collection;

use Stewart\Codegen\Action\ActionDefinition;
use Stewart\Contracts\Collection\ListCollection;

/** @extends ListCollection<ActionDefinition> */
final readonly class ActionDefinitionCollection extends ListCollection
{
    public function forEntityHandle(string $domain): self
    {
        return $this->filter(static fn(ActionDefinition $action): bool => $action->belongsOnHandle($domain));
    }
}
