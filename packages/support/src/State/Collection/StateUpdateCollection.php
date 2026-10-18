<?php

declare(strict_types=1);

namespace Stewart\Support\State\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Support\State\StateUpdate;

/**
 * @internal
 * @extends ListCollection<StateUpdate>
 */
final readonly class StateUpdateCollection extends ListCollection {}
