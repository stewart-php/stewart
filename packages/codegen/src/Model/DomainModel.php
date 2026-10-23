<?php

declare(strict_types=1);

namespace Stewart\Codegen\Model;

use Stewart\Codegen\Action\ActionModel;
use Stewart\Codegen\Attribute\AttributeModel;
use Stewart\Contracts\Entity\Collection\EntityIdCollection;

final readonly class DomainModel
{
    /**
     * @param list<AttributeModel> $attributes
     * @param list<ActionModel> $handleActions
     * @param list<ActionModel> $actions
     */
    public function __construct(
        public string $domain,
        public string $accessor,
        public string $classStem,
        public DomainTraits $traits,
        public EntityIdCollection $entityIds,
        public array $attributes,
        public array $handleActions,
        public array $actions,
    ) {}

    public function getEntitiesClass(): string
    {
        return $this->classStem . 'Entities';
    }

    public function getHandleClass(): string
    {
        return $this->classStem . 'Entity';
    }

    public function getStateClass(): string
    {
        return $this->classStem . 'State';
    }

    public function getActionsClass(): string
    {
        return $this->classStem . 'Actions';
    }

    public function isOnOff(): bool
    {
        return $this->traits->onOff;
    }

    public function isNumeric(): bool
    {
        return $this->traits->numeric;
    }

    public function hasEntities(): bool
    {
        return !$this->entityIds->isEmpty();
    }

    public function hasActions(): bool
    {
        return $this->actions !== [];
    }
}
