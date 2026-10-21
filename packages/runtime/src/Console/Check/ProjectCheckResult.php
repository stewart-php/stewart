<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console\Check;

use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\AppCatalog;
use Stewart\Runtime\App\Collection\AppDefinitionCollection;
use Stewart\Runtime\App\DiscoveryResult;
use Stewart\Runtime\Broker\Collection\WorkerSlotCollection;

final readonly class ProjectCheckResult
{
    /** @param list<string> $errors */
    private function __construct(
        public array $errors,
        public DiscoveryResult $discovered,
        public AppCatalog $catalog,
        public WorkerSlotCollection $workerSlots,
    ) {}

    public static function passed(DiscoveryResult $discovered, AppCatalog $catalog, WorkerSlotCollection $workerSlots): self
    {
        return new self([], $discovered, $catalog, $workerSlots);
    }

    /** @param list<string> $errors */
    public static function failed(array $errors): self
    {
        $none = AppIdCollection::empty();

        return new self($errors, DiscoveryResult::createEmpty(), new AppCatalog(AppDefinitionCollection::keyedByAppId([]), $none, $none), WorkerSlotCollection::empty());
    }

    public function hasPassed(): bool
    {
        return $this->errors === [];
    }
}
