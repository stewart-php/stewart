<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture\Fixtures;

use LogicException;
use Symfony\Component\Finder\Finder;

final readonly class PackageManifest
{
    private const string SIBLING_PREFIX = 'stewart-php/';

    private const array NON_SOURCE_DIRECTORIES = ['tests', 'vendor', 'var'];

    /**
     * @param list<string> $requires
     * @param list<string> $devRequires
     * @param list<string> $sourceDirectories
     * @param array<string, string> $siblingConstraints
     * @param array<string, mixed> $autoloadPsr4
     * @param array<string, mixed> $autoloadDevPsr4
     */
    private function __construct(
        public string $name,
        public string $directory,
        private array $requires,
        private array $devRequires,
        private array $sourceDirectories,
        public array $siblingConstraints,
        private array $autoloadPsr4,
        private array $autoloadDevPsr4,
        public ?string $mainBranchAlias,
    ) {}

    public static function readFromDirectory(string $directory): self
    {
        $real = realpath($directory);

        if ($real === false) {
            throw new LogicException(\sprintf('%s is not a directory.', $directory));
        }

        $json = file_get_contents($real . '/composer.json');

        if ($json === false) {
            throw new LogicException(\sprintf('Could not read %s/composer.json.', $real));
        }

        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $name = \is_array($manifest) ? $manifest['name'] ?? null : null;

        if (!\is_array($manifest) || !\is_string($name)) {
            throw new LogicException(\sprintf('%s/composer.json names no package.', $real));
        }

        return new self(
            $name,
            $real,
            self::readPackageNames($manifest, 'require'),
            self::readPackageNames($manifest, 'require-dev'),
            self::listSourceDirectories($real),
            self::readSiblingConstraints($manifest),
            self::readPsr4Mapping($manifest, 'autoload'),
            self::readPsr4Mapping($manifest, 'autoload-dev'),
            self::readMainBranchAlias($manifest),
        );
    }

    public function requiresPackage(string $package): bool
    {
        return \in_array($package, $this->requires, true);
    }

    public function requiresPackageForTests(string $package): bool
    {
        return $this->requiresPackage($package) || \in_array($package, $this->devRequires, true);
    }

    public function findTestNamespace(): ?string
    {
        foreach ($this->autoloadPsr4 as $prefix => $path) {
            if ($path === 'src/') {
                return $prefix . 'Tests\\';
            }
        }

        return null;
    }

    public function mapsTestNamespace(string $namespace): bool
    {
        return ($this->autoloadDevPsr4[$namespace] ?? null) === 'tests/';
    }

    public function hasFile(string $relative): bool
    {
        return is_file($this->directory . '/' . $relative);
    }

    public function readFile(string $relative): string
    {
        return $this->hasFile($relative) ? (string) file_get_contents($this->directory . '/' . $relative) : '';
    }

    public function ownsFile(string $realFile): bool
    {
        return str_starts_with($realFile, $this->directory . '/');
    }

    /** @return list<string> */
    public function listSourceFiles(): array
    {
        return $this->listPhpFiles($this->sourceDirectories);
    }

    /** @return list<string> */
    public function listTestFiles(): array
    {
        return $this->listPhpFiles(is_dir($this->directory . '/tests') ? ['tests'] : []);
    }

    /**
     * @param list<string> $directories
     * @return list<string>
     */
    private function listPhpFiles(array $directories): array
    {
        if ($directories === []) {
            return [];
        }

        $finder = Finder::create()
            ->files()
            ->in(array_map(fn(string $dir): string => $this->directory . '/' . $dir, $directories))
            ->name('*.php')
            ->sortByName();
        $files = [];

        foreach ($finder as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }

    /** @return list<string> */
    private static function listSourceDirectories(string $packageDirectory): array
    {
        $finder = Finder::create()
            ->directories()
            ->depth(0)
            ->in($packageDirectory)
            ->exclude(self::NON_SOURCE_DIRECTORIES)
            ->sortByName();
        $directories = [];

        foreach ($finder as $directory) {
            $directories[] = $directory->getFilename();
        }

        return $directories;
    }

    /**
     * @param array<mixed> $manifest
     * @return list<string>
     */
    private static function readPackageNames(array $manifest, string $section): array
    {
        $entries = $manifest[$section] ?? [];

        if (!\is_array($entries)) {
            return [];
        }

        $names = [];

        foreach (array_keys($entries) as $key) {
            if (\is_string($key) && $key !== 'php' && !str_starts_with($key, 'ext-')) {
                $names[] = $key;
            }
        }

        return $names;
    }

    /**
     * @param array<mixed> $manifest
     * @return array<string, string>
     */
    private static function readSiblingConstraints(array $manifest): array
    {
        $constraints = [];

        foreach (['require', 'require-dev'] as $section) {
            $entries = $manifest[$section] ?? [];

            foreach (\is_array($entries) ? $entries : [] as $package => $constraint) {
                if (\is_string($package) && str_starts_with($package, self::SIBLING_PREFIX)) {
                    $constraints[$package] = \is_string($constraint) ? $constraint : '';
                }
            }
        }

        return $constraints;
    }

    /**
     * @param array<mixed> $manifest
     * @return array<string, mixed>
     */
    private static function readPsr4Mapping(array $manifest, string $section): array
    {
        $autoload = $manifest[$section] ?? null;
        $psr4 = \is_array($autoload) ? $autoload['psr-4'] ?? [] : [];
        $mapping = [];

        foreach (\is_array($psr4) ? $psr4 : [] as $prefix => $path) {
            if (\is_string($prefix)) {
                $mapping[$prefix] = $path;
            }
        }

        return $mapping;
    }

    /** @param array<mixed> $manifest */
    private static function readMainBranchAlias(array $manifest): ?string
    {
        $extra = $manifest['extra'] ?? null;
        $aliases = \is_array($extra) ? $extra['branch-alias'] ?? null : null;
        $alias = \is_array($aliases) ? $aliases['dev-main'] ?? null : null;

        return \is_string($alias) ? $alias : null;
    }
}
