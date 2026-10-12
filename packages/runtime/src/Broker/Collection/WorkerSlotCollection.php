<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Broker\WorkerSlot;

/** @extends ListCollection<WorkerSlot> */
final readonly class WorkerSlotCollection extends ListCollection {}
