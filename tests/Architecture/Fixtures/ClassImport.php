<?php

declare(strict_types=1);

namespace Stewart\Tests\Architecture\Fixtures;

use LogicException;

final readonly class ClassImport
{
    private const string USE_LINE = '/^use (?:(?<kind>function|const) )?(?<name>[A-Za-z_][\w\\\\]*)(?: as \w+)?;$/';

    public function __construct(
        public string $file,
        public int $line,
        public string $className,
    ) {}

    /** @return list<self> */
    public static function readAllFromFile(string $file): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new LogicException(\sprintf('Could not read %s.', $file));
        }

        $imports = [];

        foreach ($lines as $index => $line) {
            if (!str_starts_with($line, 'use ')) {
                continue;
            }

            if (preg_match(self::USE_LINE, $line, $match) !== 1) {
                throw new LogicException(\sprintf('Unsupported use statement at %s:%d.', $file, $index + 1));
            }

            if ($match['kind'] !== '') {
                continue;
            }

            $imports[] = new self($file, $index + 1, $match['name']);
        }

        return $imports;
    }
}
