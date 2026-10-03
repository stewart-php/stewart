<?php

declare(strict_types=1);

namespace Stewart\Runtime\Kernel;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final readonly class SyntheticServices
{
    /** @param array<string, object> $services */
    public function __construct(private array $services = []) {}

    public function withService(string $id, object $service): self
    {
        return new self([...$this->services, $id => $service]);
    }

    public function withOverrides(self $overrides): self
    {
        return new self([...$this->services, ...$overrides->services]);
    }

    public function declareDefinitionsOn(ContainerBuilder $builder): void
    {
        foreach ($this->services as $id => $service) {
            if ($builder->hasAlias($id)) {
                $builder->removeAlias($id);
            }

            // Public so an unused one survives compilation and can still be set.
            $builder->setDefinition($id, new Definition($service::class)->setSynthetic(true)->setPublic(true));
        }
    }

    // Call after compile: setting earlier drops the definition, and factories using it fail.
    public function setInstancesOn(ContainerBuilder $builder): void
    {
        foreach ($this->services as $id => $service) {
            $builder->set($id, $service);
        }
    }
}
