<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Stewart\Runtime\App\Collection\DiscoveredAppCollection;
use Stewart\Runtime\App\Collection\UnloadableAppFileCollection;
use Stewart\Runtime\App\Collection\UnmarkedAppCollection;

final readonly class DiscoveryResult
{
    /** @param list<class-string> $templateClasses */
    public function __construct(
        public DiscoveredAppCollection $apps,
        public UnmarkedAppCollection $unmarked,
        public UnloadableAppFileCollection $unloadable,
        public array $templateClasses = [],
    ) {}

    public static function createEmpty(): self
    {
        return new self(DiscoveredAppCollection::empty(), UnmarkedAppCollection::empty(), UnloadableAppFileCollection::empty());
    }

    /** @param list<DiscoveredApp> $configured */
    public function withConfiguredApps(array $configured): self
    {
        return new self(DiscoveredAppCollection::fromApps([...$this->apps->listValues(), ...$configured]), $this->unmarked, $this->unloadable, $this->templateClasses);
    }

    /** @return list<class-string> */
    public function listUnusedTemplateClasses(): array
    {
        $used = $this->apps->mapToList(static fn(DiscoveredApp $app): string => $app->class);

        return array_values(array_diff($this->templateClasses, $used));
    }
}
