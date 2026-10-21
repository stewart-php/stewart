<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\AppDefinition;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\Ipc\Collection\WorkerAppCollection;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Model\WorkerId;

final readonly class WorkerSlot
{
    public function __construct(
        public WorkerId $workerId,
        public AppDefinitionCollection $apps,
    ) {}

    public function listAppIds(): AppIdCollection
    {
        return AppIdCollection::fromList($this->apps->mapToList(static fn(AppDefinition $app) => $app->id));
    }

    public function listWorkerApps(): WorkerAppCollection
    {
        return WorkerAppCollection::fromList($this->apps->mapToList(
            static fn(AppDefinition $app): WorkerApp => new WorkerApp($app->id, $app->class, $app->options, $app->verbosity),
        ));
    }
}
