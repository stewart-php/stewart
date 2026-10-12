<?php

declare(strict_types=1);

namespace Stewart\Contracts\Trigger\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Contracts\Trigger\HaTrigger;

/** @extends ListCollection<HaTrigger> */
final readonly class HaTriggerCollection extends ListCollection {}
