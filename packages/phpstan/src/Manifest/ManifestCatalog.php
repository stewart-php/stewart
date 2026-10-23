<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Manifest;

final readonly class ManifestCatalog
{
    /**
     * @param array<string, true> $entityIds
     * @param array<string, true> $ignoredEntityIds
     * @param array<string, true>|null $actionNames
     */
    private function __construct(
        private array $entityIds,
        private array $ignoredEntityIds,
        private ?array $actionNames,
    ) {}

    /**
     * @param list<string> $entityIds
     * @param list<string> $ignoredEntityIds
     * @param list<string>|null $actionNames
     */
    public static function fromLists(array $entityIds, array $ignoredEntityIds, ?array $actionNames): self
    {
        return new self(
            array_fill_keys($entityIds, true),
            array_fill_keys($ignoredEntityIds, true),
            $actionNames === null ? null : array_fill_keys($actionNames, true),
        );
    }

    public function knowsEntity(string $entityId): bool
    {
        return isset($this->entityIds[$entityId]) || isset($this->ignoredEntityIds[$entityId]);
    }

    public function isIgnoredEntity(string $entityId): bool
    {
        return isset($this->ignoredEntityIds[$entityId]);
    }

    /** @return list<string> */
    public function listKnownEntityIds(): array
    {
        return array_map(strval(...), [...array_keys($this->entityIds), ...array_keys($this->ignoredEntityIds)]);
    }

    public function listsActionNames(): bool
    {
        return $this->actionNames !== null;
    }

    public function knowsAction(string $actionName): bool
    {
        return isset($this->actionNames[$actionName]);
    }

    /** @return list<string> */
    public function listActionNames(): array
    {
        return array_map(strval(...), array_keys($this->actionNames ?? []));
    }

    public function computeHash(): string
    {
        return hash('xxh128', serialize([$this->entityIds, $this->ignoredEntityIds, $this->actionNames]));
    }
}
