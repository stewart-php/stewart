<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture\Fixtures;

use Symfony\Component\Finder\Finder;

final readonly class RepositoryFiles
{
    private const array TEST_DIRECTORIES = ['tests', 'packages/*/tests'];

    public string $rootPath;

    public function __construct()
    {
        $this->rootPath = \dirname(__DIR__, 3);
    }

    /**
     * @param list<string> $directoryPatterns
     * @return list<string>
     */
    public function listFilesIn(array $directoryPatterns, string $namePattern = '*.php'): array
    {
        // Finder throws on a pattern that matches no directory, so a moved folder fails the scan instead of emptying it.
        $finder = Finder::create()
            ->files()
            ->in(array_map(fn(string $pattern): string => $this->rootPath . '/' . $pattern, $directoryPatterns))
            ->name($namePattern)
            ->sortByName();
        $files = [];

        foreach ($finder as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }

    /** @return list<string> */
    public function listTestFiles(string $namePattern = '*.php'): array
    {
        return $this->listFilesIn(self::TEST_DIRECTORIES, $namePattern);
    }

    public function toRelativePath(string $absolutePath): string
    {
        return substr($absolutePath, \strlen($this->rootPath) + 1);
    }
}
