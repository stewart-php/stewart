<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture\Fixtures;

final readonly class ImportOwner
{
    // phpstan.phar ships PhpParser; the vendor copy comes only with tools such as PHPUnit.
    private const array BUNDLED_BY = ['nikic/php-parser' => 'phpstan/phpstan'];

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
        return $this->core
            || $this->name === $manifest->name
            || $manifest->requiresPackage($this->name)
            || (isset(self::BUNDLED_BY[$this->name]) && $manifest->requiresPackage(self::BUNDLED_BY[$this->name]));
    }

    public function isSatisfiedForTestsBy(PackageManifest $manifest): bool
    {
        return $this->core
            || $this->name === $manifest->name
            || $manifest->requiresPackageForTests($this->name)
            || (isset(self::BUNDLED_BY[$this->name]) && $manifest->requiresPackageForTests(self::BUNDLED_BY[$this->name]));
    }
}
