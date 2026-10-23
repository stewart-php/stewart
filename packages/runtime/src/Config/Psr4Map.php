<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

final readonly class Psr4Map
{
    /** @param array<string, array<int, string>> $directoriesByNamespace */
    public function __construct(private array $directoriesByNamespace) {}

    /** @return list<string> */
    public function listDirectoriesFor(string $namespacePrefix): array
    {
        return array_values($this->directoriesByNamespace[$namespacePrefix] ?? []);
    }

    // The deepest mapped directory wins, as Composer resolves the most specific prefix.
    public function findAutoloadRootContaining(string $directory): ?AutoloadRoot
    {
        $found = null;

        foreach ($this->directoriesByNamespace as $namespacePrefix => $directories) {
            foreach ($directories as $mapped) {
                $candidate = realpath($mapped);

                if ($candidate === false || ($candidate !== $directory && !str_starts_with($directory, $candidate . \DIRECTORY_SEPARATOR))) {
                    continue;
                }

                if ($found === null || \strlen($candidate) > \strlen($found->directory)) {
                    $found = new AutoloadRoot($namespacePrefix, $candidate);
                }
            }
        }

        return $found;
    }

    /** @return list<string> */
    public function listPrefixesMappedTo(string $directory): array
    {
        $wanted = self::normalizePath($directory);
        $prefixes = [];

        foreach ($this->directoriesByNamespace as $namespacePrefix => $directories) {
            foreach ($directories as $mapped) {
                if (self::normalizePath($mapped) === $wanted) {
                    $prefixes[] = $namespacePrefix;

                    break;
                }
            }
        }

        return $prefixes;
    }

    // Composer's base directory contains `..`, and realpath fails before the first run.
    private static function normalizePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            match ($segment) {
                '', '.' => null,
                '..' => array_pop($segments),
                default => $segments[] = $segment,
            };
        }

        return '/' . implode('/', $segments);
    }
}
