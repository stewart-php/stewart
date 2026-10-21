<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Reload;

use RuntimeException;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

final readonly class SourceScanner
{
    private const string PHP_FILES = '*.php';

    /** @param list<string> $paths */
    public function scanFingerprint(array $paths): SourceFingerprint
    {
        clearstatcache();
        $entries = [];

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                $entries[] = $this->describeFile(new SplFileInfo($path));

                continue;
            }

            foreach (new Finder()->files()->in($path)->name(self::PHP_FILES)->sortByName() as $file) {
                $entries[] = $this->describeFile($file);
            }
        }

        return new SourceFingerprint(hash('xxh128', implode("\n", $entries)));
    }

    // The inode catches an editor that saves by renaming a new file over the old one.
    private function describeFile(SplFileInfo $file): string
    {
        try {
            return \sprintf('%s|%d|%d|%d', $file->getPathname(), $file->getMTime(), $file->getSize(), $file->getInode());
        } catch (RuntimeException) {
            return $file->getPathname() . '|absent';
        }
    }
}
