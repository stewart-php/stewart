<?php

declare(strict_types=1);

namespace Stewart\Runtime\Container;

use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Runtime\App\GeneratedRoots;
use Stewart\Runtime\Config\StewartConfig;
use Stewart\Runtime\Exception\AppException;
use Stewart\Runtime\Ipc\Message\Bootstrap;

final readonly class AppContainerBlueprint
{
    public function __construct(
        public ?string $servicesFile,
        public ?GeneratedRoots $generated,
        public AppIdCollection $knownAppIds,
        public bool $storeConfigured,
        public bool $mqttConfigured,
    ) {}

    /** @throws AppException */
    public static function fromBootstrap(Bootstrap $bootstrap): self
    {
        return new self(
            servicesFile: $bootstrap->settings->servicesFile,
            generated: GeneratedRoots::fromNamespace($bootstrap->settings->generatedNamespace),
            knownAppIds: $bootstrap->knownAppIds->collection,
            storeConfigured: $bootstrap->store !== null,
            mqttConfigured: $bootstrap->settings->mqttEnabled,
        );
    }

    public static function fromProjectConfig(StewartConfig $config, ?GeneratedRoots $generated, AppIdCollection $knownAppIds, string $userServicesFile): self
    {
        return new self(
            servicesFile: is_file($userServicesFile) ? $userServicesFile : null,
            generated: $generated,
            knownAppIds: $knownAppIds,
            storeConfigured: $config->persistence->isStoreConfigured(),
            mqttConfigured: $config->mqtt !== null,
        );
    }
}
