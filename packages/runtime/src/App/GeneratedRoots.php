<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Stewart\Contracts\Generated\Manifest;

final readonly class GeneratedRoots
{
    /**
     * @param class-string $entitiesClass
     * @param class-string $actionsClass
     * @param class-string<Manifest>|null $manifestClass
     */
    private function __construct(
        public string $entitiesClass,
        public string $actionsClass,
        public ?string $manifestClass,
    ) {}

    public static function fromNamespace(string $generatedNamespace): ?self
    {
        $namespace = trim($generatedNamespace, '\\');
        $entities = $namespace . '\\Entities';
        $actions = $namespace . '\\Actions';

        if (!class_exists($entities) || !class_exists($actions)) {
            return null;
        }

        $manifest = $namespace . '\\Manifest';

        return new self($entities, $actions, is_a($manifest, Manifest::class, true) ? $manifest : null);
    }
}
