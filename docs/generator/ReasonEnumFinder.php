<?php

declare(strict_types=1);

namespace Stewart\Docs\Generator;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Stewart\Contracts\Exception\ExceptionReason;

final readonly class ReasonEnumFinder
{
    private const string NAMESPACE_LINE = '/^namespace ([^;]+);/m';

    /** @return list<class-string<ExceptionReason>> */
    public function findReasonEnums(string $packagesDirectory): array
    {
        $enums = [];

        foreach (glob($packagesDirectory . '/*/src', \GLOB_ONLYDIR) ?: [] as $sourceDirectory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory)) as $file) {
                if (!$file instanceof SplFileInfo || !str_ends_with($file->getFilename(), 'Error.php')) {
                    continue;
                }

                $class = $this->readClassName($file);

                if ($class !== null && enum_exists($class) && is_subclass_of($class, ExceptionReason::class)) {
                    $enums[$file->getPathname()] = $class;
                }
            }
        }

        ksort($enums);

        return array_values($enums);
    }

    private function readClassName(SplFileInfo $file): ?string
    {
        $source = file_get_contents($file->getPathname());

        if ($source === false || preg_match(self::NAMESPACE_LINE, $source, $match) !== 1) {
            return null;
        }

        return $match[1] . '\\' . $file->getBasename('.php');
    }
}
