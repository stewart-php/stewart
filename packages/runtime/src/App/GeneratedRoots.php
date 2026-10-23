<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use Stewart\Contracts\Generated\Manifest;
use Stewart\Runtime\Exception\AppError;
use Stewart\Runtime\Exception\AppException;

final readonly class GeneratedRoots
{
    private const int MAJOR_WITH_SERVICES_ROOT = 1;

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

    /** @throws AppException */
    public static function fromNamespace(string $generatedNamespace): ?self
    {
        $namespace = trim($generatedNamespace, '\\');
        $entities = $namespace . '\\Entities';
        $actions = $namespace . '\\Actions';

        // Only the roots are probed before the tree is known to be current: an older Manifest or entity handle
        // would fail to load against today's contracts, and PHP cannot catch that.
        if (!class_exists($actions)) {
            if (class_exists($namespace . '\\Services')) {
                throw AppException::createForReason(AppError::GeneratedCodeFromOlderMajor, namespace: $namespace, major: self::MAJOR_WITH_SERVICES_ROOT);
            }

            return null;
        }

        if (!class_exists($entities)) {
            return null;
        }

        $manifest = $namespace . '\\Manifest';

        return new self($entities, $actions, is_a($manifest, Manifest::class, true) ? $manifest : null);
    }
}
