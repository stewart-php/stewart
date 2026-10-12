<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Worker\AppSlot;

/** @extends ListCollection<AppSlot> */
final readonly class AppSlotCollection extends ListCollection {}
