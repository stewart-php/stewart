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
}
