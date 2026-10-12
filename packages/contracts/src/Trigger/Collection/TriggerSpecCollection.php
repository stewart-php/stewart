<?php

declare(strict_types=1);

namespace Stewart\Contracts\Trigger\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Contracts\Trigger\TriggerSpec;

/** @extends ListCollection<TriggerSpec> */
final readonly class TriggerSpecCollection extends ListCollection
{
    public function withTriggerSpec(TriggerSpec $spec): self
    {
        return $this->withAppendedElement($spec);
    }
}
