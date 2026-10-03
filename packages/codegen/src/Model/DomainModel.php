<?php

declare(strict_types=1);

namespace Stewart\Codegen\Model;

use Stewart\Codegen\Attribute\Collection\AttributeModelCollection;
use Stewart\Codegen\Entity\Collection\EntityModelCollection;
use Stewart\Codegen\Service\Collection\ServiceModelCollection;

final readonly class DomainModel
{
    public function __construct(
        public string $domain,
        public string $accessor,
        public string $classStem,
        public DomainTraits $traits,
        public EntityModelCollection $entities,
        public AttributeModelCollection $attributes,
        public ServiceModelCollection $handleServices,
        public ServiceModelCollection $services,
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
        return !$this->entities->isEmpty();
    }

    public function hasServices(): bool
    {
        return !$this->services->isEmpty();
    }
}
