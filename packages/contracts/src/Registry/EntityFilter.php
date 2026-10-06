<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Contracts\Exception\SelectorException;
use Stewart\Contracts\Registry\Collection\AreaIdCollection;
use Stewart\Contracts\Registry\Collection\DeviceIdCollection;
use Stewart\Contracts\Registry\Collection\FloorIdCollection;
use Stewart\Contracts\Registry\Collection\LabelIdCollection;
use Stewart\Contracts\Registry\Collection\RegistryIdCollection;
use Stewart\Contracts\Selector\Collection\SelectorCollection;
use Stewart\Contracts\Selector\Selector;

final readonly class EntityFilter
{
    /** @param list<string> $domains */
    private function __construct(
        private AreaIdCollection $areaIds,
        private FloorIdCollection $floorIds,
        private LabelIdCollection $labelIds,
        private DeviceIdCollection $deviceIds,
        private array $domains,
        private ?Selector $selector,
    ) {}

    /** @throws IdentifierException */
    public static function inArea(AreaId|string $areaId, AreaId|string ...$moreAreaIds): self
    {
        return self::unconstrained()->withArea($areaId, ...$moreAreaIds);
    }

    /** @throws IdentifierException */
    public static function onFloor(FloorId|string $floorId, FloorId|string ...$moreFloorIds): self
    {
        return self::unconstrained()->withFloor($floorId, ...$moreFloorIds);
    }

    /** @throws IdentifierException */
    public static function labelled(LabelId|string $labelId, LabelId|string ...$moreLabelIds): self
    {
        return self::unconstrained()->withLabel($labelId, ...$moreLabelIds);
    }

    /** @throws IdentifierException */
    public static function ofDevice(DeviceId|string $deviceId, DeviceId|string ...$moreDeviceIds): self
    {
        return self::unconstrained()->withDevice($deviceId, ...$moreDeviceIds);
    }

    public static function inDomain(string $domain, string ...$moreDomains): self
    {
        return self::unconstrained()->withDomain($domain, ...$moreDomains);
    }

    /** @throws SelectorException */
    public static function matching(string|EntityId|Selector|SelectorCollection $selector): self
    {
        return self::unconstrained()->withSelector($selector);
    }

    /** @throws IdentifierException */
    public function withArea(AreaId|string ...$areaIds): self
    {
        $added = array_map(AreaId::fromStringOrId(...), $areaIds);

        return new self(AreaIdCollection::fromIds([...$this->areaIds, ...$added]), $this->floorIds, $this->labelIds, $this->deviceIds, $this->domains, $this->selector);
    }

    /** @throws IdentifierException */
    public function withFloor(FloorId|string ...$floorIds): self
    {
        $added = array_map(FloorId::fromStringOrId(...), $floorIds);

        return new self($this->areaIds, FloorIdCollection::fromIds([...$this->floorIds, ...$added]), $this->labelIds, $this->deviceIds, $this->domains, $this->selector);
    }

    /** @throws IdentifierException */
    public function withLabel(LabelId|string ...$labelIds): self
    {
        $added = array_map(LabelId::fromStringOrId(...), $labelIds);

        return new self($this->areaIds, $this->floorIds, LabelIdCollection::fromIds([...$this->labelIds, ...$added]), $this->deviceIds, $this->domains, $this->selector);
    }

    /** @throws IdentifierException */
    public function withDevice(DeviceId|string ...$deviceIds): self
    {
        $added = array_map(DeviceId::fromStringOrId(...), $deviceIds);

        return new self($this->areaIds, $this->floorIds, $this->labelIds, DeviceIdCollection::fromIds([...$this->deviceIds, ...$added]), $this->domains, $this->selector);
    }

    public function withDomain(string ...$domains): self
    {
        return new self($this->areaIds, $this->floorIds, $this->labelIds, $this->deviceIds, array_values(array_unique([...$this->domains, ...$domains])), $this->selector);
    }

    /** @throws SelectorException */
    public function withSelector(string|EntityId|Selector|SelectorCollection $selector): self
    {
        $added = Selector::fromSpec($selector);
        $combined = $this->selector === null ? $added : Selector::anyOf($this->selector, $added);

        return new self($this->areaIds, $this->floorIds, $this->labelIds, $this->deviceIds, $this->domains, $combined);
    }

    public function matchesEntity(EntityId $entityId, Registry $registry): bool
    {
        if ($this->domains !== [] && !\in_array($entityId->domain, $this->domains, true)) {
            return false;
        }

        if ($this->selector !== null && !$this->selector->matches($entityId->value)) {
            return false;
        }

        if ($this->areaIds->isEmpty() && $this->floorIds->isEmpty() && $this->labelIds->isEmpty() && $this->deviceIds->isEmpty()) {
            return true;
        }

        return $this->matchesPlacement($registry->findEntityPlacement($entityId));
    }

    public function toCanonicalKey(): string
    {
        $domains = $this->domains;
        sort($domains);

        return 'entity-filter:' . json_encode([
            'areas' => $this->listSortedValues($this->areaIds),
            'floors' => $this->listSortedValues($this->floorIds),
            'labels' => $this->listSortedValues($this->labelIds),
            'devices' => $this->listSortedValues($this->deviceIds),
            'domains' => $domains,
            'selector' => $this->selector?->toCanonicalKey(),
        ], \JSON_THROW_ON_ERROR);
    }

    private static function unconstrained(): self
    {
        return new self(AreaIdCollection::empty(), FloorIdCollection::empty(), LabelIdCollection::empty(), DeviceIdCollection::empty(), [], null);
    }

    // Home Assistant skips hidden and config or diagnostic entities reached through the registry.
    private function matchesPlacement(EntityPlacement $placement): bool
    {
        return $placement->indirectlyTargetable
            && $this->containsWhenConstrained($this->areaIds, $placement->areaId)
            && $this->containsWhenConstrained($this->floorIds, $placement->floorId)
            && $this->containsWhenConstrained($this->deviceIds, $placement->deviceId)
            && ($this->labelIds->isEmpty() || $this->labelIds->containsAnyOf($placement->labelIds));
    }

    /**
     * @template T of RegistryId
     * @param RegistryIdCollection<T> $ids
     * @param T|null $id
     */
    private function containsWhenConstrained(RegistryIdCollection $ids, ?RegistryId $id): bool
    {
        return $ids->isEmpty() || ($id !== null && $ids->contains($id));
    }

    /**
     * @template T of RegistryId
     * @param RegistryIdCollection<T> $ids
     * @return list<string>
     */
    private function listSortedValues(RegistryIdCollection $ids): array
    {
        $values = array_values(array_unique($ids->toStrings()));
        sort($values);

        return $values;
    }
}
