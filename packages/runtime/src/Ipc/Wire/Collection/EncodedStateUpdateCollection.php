<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Ipc\Wire\EncodedStateUpdate;

/** @extends ListCollection<EncodedStateUpdate> */
final readonly class EncodedStateUpdateCollection extends ListCollection {}
