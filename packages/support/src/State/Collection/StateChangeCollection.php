<?php

declare(strict_types=1);

namespace Stewart\Support\State\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Contracts\State\StateChange;

/**
 * @internal
 * @extends ListCollection<StateChange>
 */
final readonly class StateChangeCollection extends ListCollection {}
