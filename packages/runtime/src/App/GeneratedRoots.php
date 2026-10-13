<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Stewart\Contracts\Generated\Manifest;
use Stewart\Runtime\Ipc\WorkerSettings;

final readonly class GeneratedRoots
{
    /**
     * @param class-string $entitiesClass
     * @param class-string $servicesClass
     * @param class-string<Manifest>|null $manifestClass
     */
    private function __construct(
        public string $entitiesClass,
        public string $servicesClass,
        public ?string $manifestClass,
    ) {}

    public static function fromWorkerSettings(WorkerSettings $workerSettings): ?self
    {
        return self::fromNamespace($workerSettings->generatedNamespace);
    }

    public static function fromNamespace(string $generatedNamespace): ?self
    {
        $namespace = trim($generatedNamespace, '\\');
        $entities = $namespace . '\\Entities';
        $services = $namespace . '\\Services';

        if (!class_exists($entities) || !class_exists($services)) {
            return null;
        }

        $manifest = $namespace . '\\Manifest';

        return new self($entities, $services, is_a($manifest, Manifest::class, true) ? $manifest : null);
    }
}
