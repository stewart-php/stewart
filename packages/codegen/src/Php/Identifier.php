<?php

declare(strict_types=1);

namespace Stewart\Codegen\Php;

use function Symfony\Component\String\u;

final class Identifier
{
    private function __construct() {}

    public static function convertToCamelCase(string $raw): string
    {
        $words = self::splitIntoAsciiWords($raw);

        if ($words === []) {
            return '_';
        }

        $first = array_shift($words);
        $name = strtolower($first) . implode('', array_map(self::capitalizeWord(...), $words));

        return ctype_digit($name[0]) ? '_' . $name : $name;
    }

    public static function startsWithLetter(string $raw): bool
    {
        $words = self::splitIntoAsciiWords($raw);

        return $words !== [] && ctype_alpha($words[0][0]);
    }

    public static function convertToPascalCase(string $raw): string
    {
        $name = implode('', array_map(self::capitalizeWord(...), self::splitIntoAsciiWords($raw)));

        if ($name === '') {
            return '_';
        }

        return ctype_digit($name[0]) ? '_' . $name : $name;
    }

    /** @return list<string> */
    private static function splitIntoAsciiWords(string $raw): array
    {
        $split = preg_split('/[^A-Za-z0-9]+/', u($raw)->ascii()->toString(), -1, \PREG_SPLIT_NO_EMPTY);

        return $split === false ? [] : $split;
    }

    private static function capitalizeWord(string $word): string
    {
        return ucfirst(strtolower($word));
    }
}
