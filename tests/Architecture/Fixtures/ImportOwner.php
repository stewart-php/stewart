<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture\Fixtures;

final readonly class ImportOwner
{
    private function __construct(
        public string $name,
        private bool $core,
    ) {}

    public static function createForCore(): self
    {
        return new self('php', true);
    }

    public static function createForPackage(string $name): self
    {
        return new self($name, false);
    }

    public static function createForOutsidePath(string $realPath): self
    {
        return new self($realPath, false);
    }

    public function isSatisfiedBy(PackageManifest $manifest): bool
    {
        return $this->core || $this->name === $manifest->name || $manifest->requiresPackage($this->name);
    }

    public function isSatisfiedForTestsBy(PackageManifest $manifest): bool
    {
        return $this->core || $this->name === $manifest->name || $manifest->requiresPackageForTests($this->name);
    }
}
