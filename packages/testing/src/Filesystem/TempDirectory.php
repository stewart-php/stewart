<?php

declare(strict_types=1);

namespace Stewart\Testing\Filesystem;

use RuntimeException;

final readonly class TempDirectory
{
    private function __construct(public string $path) {}

    public static function createWithPrefix(string $prefix): self
    {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));

        if (!mkdir($path, 0o700)) {
            throw new RuntimeException('Could not create the temporary directory ' . $path);
        }

        return new self($path);
    }

    public function getFilePath(string $name): string
    {
        return $this->path . '/' . $name;
    }

    public function remove(): void
    {
        self::removeTree($this->path);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            is_dir($child) && !is_link($child) ? self::removeTree($child) : unlink($child);
        }

        rmdir($path);
    }
}
