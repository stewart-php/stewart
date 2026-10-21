<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Model\AppVerbosity;

/** @extends ListCollection<WorkerApp> */
final readonly class WorkerAppCollection extends ListCollection
{
    /** @return array<string, AppVerbosity> */
    public function listVerbositiesByAppId(): array
    {
        $verbosities = [];

        foreach ($this as $app) {
            $verbosities[$app->id->value] = $app->verbosity;
        }

        return $verbosities;
    }
}
