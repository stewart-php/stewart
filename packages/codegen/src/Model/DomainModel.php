<?php

declare(strict_types=1);

namespace Stewart\Codegen\Model;

use Stewart\Codegen\Attribute\AttributeModel;
use Stewart\Codegen\Service\ServiceModel;
use Stewart\Contracts\Entity\Collection\EntityIdCollection;

final readonly class DomainModel
{
    /**
     * @param list<AttributeModel> $attributes
     * @param list<ServiceModel> $handleServices
     * @param list<ServiceModel> $services
     */
    public function __construct(
        public string $domain,
        public string $accessor,
        public string $classStem,
        public DomainTraits $traits,
        public EntityIdCollection $entityIds,
        public array $attributes,
        public array $handleServices,
        public array $services,
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

    public function getServicesClass(): string
    {
        return $this->classStem . 'Services';
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

    public function hasServices(): bool
    {
        return $this->services !== [];
    }
}
