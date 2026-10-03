<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use Stewart\Runtime\Ipc\Collection\WorkerAppCollection;
use Stewart\Runtime\Ipc\WorkerApp;
use Stewart\Runtime\Json\JsonFragment;
use Stewart\Runtime\Json\WireMapper;

final readonly class WorkerAppsFragment implements JsonFragment
{
    private function __construct(public WorkerAppCollection $collection) {}

    public static function fromCollection(WorkerAppCollection $apps): self
    {
        return new self($apps);
    }

    public static function fromDecodedValue(mixed $value, string $path, WireMapper $mapper): static
    {
        return new self(WorkerAppCollection::fromApps($mapper->decodeObjectList(WorkerApp::class, $value, $path)));
    }

    public function encodeToJson(WireMapper $mapper): string
    {
        return $mapper->encodeObjectList($this->collection->listValues());
    }
}
