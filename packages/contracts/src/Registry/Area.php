<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry;

use Stewart\Contracts\Registry\Collection\LabelIdCollection;
use Stewart\Contracts\Wire\ListOf;

final readonly class Area
{
    /**
     * @param list<string> $aliases
     * @param list<LabelId> $labelIds
     */
    public function __construct(
        public AreaId $areaId,
        public string $name,
        public ?FloorId $floorId = null,
        #[ListOf('string')]
        public array $aliases = [],
        #[ListOf(LabelId::class)]
        public array $labelIds = [],
        public ?string $icon = null,
    ) {}

    public function listLabelIds(): LabelIdCollection
    {
        return LabelIdCollection::fromIds($this->labelIds);
    }

    public function isNamed(string $name): bool
    {
        return RegistryNames::containsName($name, $this->name, ...$this->aliases);
    }
}
