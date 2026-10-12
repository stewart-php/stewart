<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Ipc\WorkerApp;

/** @extends ListCollection<WorkerApp> */
final readonly class WorkerAppCollection extends ListCollection {}
