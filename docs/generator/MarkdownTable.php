<?php

declare(strict_types=1);

namespace Stewart\Docs\Generator;

final readonly class MarkdownTable
{
    /**
     * @param list<string> $headings
     * @param list<list<string>> $rows
     */
    public static function formatTable(array $headings, array $rows): string
    {
        $lines = [self::formatRow($headings), self::formatRow(array_fill(0, \count($headings), '---'))];

        foreach ($rows as $row) {
            $lines[] = self::formatRow($row);
        }

        return implode("\n", $lines);
    }

    public static function formatCode(string $text): string
    {
        return $text === '' ? '' : '`' . str_replace('`', "'", $text) . '`';
    }

    /** @param list<string> $cells */
    private static function formatRow(array $cells): string
    {
        return '| ' . implode(' | ', array_map(static fn(string $cell): string => str_replace(['|', "\n"], ['\|', ' '], $cell), $cells)) . ' |';
    }
}
