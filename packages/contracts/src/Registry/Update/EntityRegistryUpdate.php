<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry\Update;

use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\Collection\EntityAliasCollection;
use Stewart\Contracts\Registry\Collection\LabelIdCollection;
use Stewart\Contracts\Registry\EntityAlias;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Registry\RegisteredEntity;

// A null field leaves that part of the registry entry as it is.
final readonly class EntityRegistryUpdate
{
    public const string CHANGED_BY_USER = 'user';

    public function __construct(
        public ?EntityNameChange $name = null,
        public ?EntityIconChange $icon = null,
        public ?EntityAreaChange $area = null,
        public ?EntityLabelsChange $labels = null,
        public ?EntityAliasesChange $aliases = null,
        public ?bool $hidden = null,
        public ?bool $disabled = null,
    ) {}

    public function withName(string $name): self
    {
        return clone($this, ['name' => new EntityNameChange($name)]);
    }

    public function withoutName(): self
    {
        return clone($this, ['name' => new EntityNameChange(null)]);
    }

    public function withIcon(string $icon): self
    {
        return clone($this, ['icon' => new EntityIconChange($icon)]);
    }

    public function withoutIcon(): self
    {
        return clone($this, ['icon' => new EntityIconChange(null)]);
    }

    /** @throws IdentifierException */
    public function withArea(AreaId|string $areaId): self
    {
        return clone($this, ['area' => new EntityAreaChange(AreaId::fromStringOrId($areaId))]);
    }

    public function withoutArea(): self
    {
        return clone($this, ['area' => new EntityAreaChange(null)]);
    }

    /** @throws IdentifierException */
    public function withLabels(LabelId|string ...$labelIds): self
    {
        return clone($this, ['labels' => EntityLabelsChange::replacingWith($this->collectLabelIds($labelIds))]);
    }

    /** @throws IdentifierException */
    public function withAddedLabels(LabelId|string ...$labelIds): self
    {
        return clone($this, ['labels' => ($this->labels ?? new EntityLabelsChange())->withAdded($this->collectLabelIds($labelIds))]);
    }

    /** @throws IdentifierException */
    public function withRemovedLabels(LabelId|string ...$labelIds): self
    {
        return clone($this, ['labels' => ($this->labels ?? new EntityLabelsChange())->withRemoved($this->collectLabelIds($labelIds))]);
    }

    public function withAliases(EntityAlias|string ...$aliases): self
    {
        return clone($this, ['aliases' => EntityAliasesChange::replacingWith($this->collectAliases($aliases))]);
    }

    public function withAddedAliases(EntityAlias|string ...$aliases): self
    {
        return clone($this, ['aliases' => ($this->aliases ?? new EntityAliasesChange())->withAdded($this->collectAliases($aliases))]);
    }

    public function withRemovedAliases(EntityAlias|string ...$aliases): self
    {
        return clone($this, ['aliases' => ($this->aliases ?? new EntityAliasesChange())->withRemoved($this->collectAliases($aliases))]);
    }

    public function withHidden(bool $hidden): self
    {
        return clone($this, ['hidden' => $hidden]);
    }

    public function withDisabled(bool $disabled): self
    {
        return clone($this, ['disabled' => $disabled]);
    }

    public function isEmpty(): bool
    {
        return $this->name === null
            && $this->icon === null
            && $this->area === null
            && $this->labels === null
            && $this->aliases === null
            && $this->hidden === null
            && $this->disabled === null;
    }

    public function needsCurrentEntry(): bool
    {
        return $this->labels?->needsCurrentLabels() === true || $this->aliases?->needsCurrentAliases() === true;
    }

    public function resolveAgainst(RegisteredEntity $current): self
    {
        return clone($this, [
            'labels' => $this->labels?->resolveAgainst($current->listLabelIds()),
            'aliases' => $this->aliases?->resolveAgainst($current->listAliases() ?? EntityAliasCollection::empty()),
        ]);
    }

    public function applyTo(RegisteredEntity $current): RegisteredEntity
    {
        return new RegisteredEntity(
            entityId: $current->entityId,
            deviceId: $current->deviceId,
            areaId: $this->area === null ? $current->areaId : $this->area->areaId,
            labelIds: $this->labels?->applyTo($current->listLabelIds())->listValues() ?? $current->labelIds,
            name: $this->name === null ? $current->name : $this->name->name,
            entityCategory: $current->entityCategory,
            hiddenBy: $this->hidden === null ? $current->hiddenBy : ($this->hidden ? self::CHANGED_BY_USER : null),
            disabledBy: $this->disabled === null ? $current->disabledBy : ($this->disabled ? self::CHANGED_BY_USER : null),
            icon: $this->icon === null ? $current->icon : $this->icon->icon,
            aliases: $this->aliases?->applyTo($current->listAliases() ?? EntityAliasCollection::empty())->listValues() ?? $current->aliases,
        );
    }

    /**
     * @param array<LabelId|string> $labelIds
     * @throws IdentifierException
     */
    private function collectLabelIds(array $labelIds): LabelIdCollection
    {
        return LabelIdCollection::fromIds(array_map(LabelId::fromStringOrId(...), array_values($labelIds)));
    }

    /** @param array<EntityAlias|string> $aliases */
    private function collectAliases(array $aliases): EntityAliasCollection
    {
        return EntityAliasCollection::fromAliases(array_map(
            static fn(EntityAlias|string $alias): EntityAlias => $alias instanceof EntityAlias ? $alias : EntityAlias::named($alias),
            array_values($aliases),
        ));
    }
}
