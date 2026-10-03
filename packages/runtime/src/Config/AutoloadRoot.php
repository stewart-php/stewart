<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

final readonly class AutoloadRoot
{
    private const string PHP_EXTENSION = '.php';

    public function __construct(
        public string $namespacePrefix,
        public string $directory,
    ) {}

    /** @return class-string|null */
    public function findClassNameForFile(string $file): ?string
    {
        if (!str_starts_with($file, $this->directory . \DIRECTORY_SEPARATOR)) {
            return null;
        }

        $relative = substr($file, \strlen($this->directory) + 1, -\strlen(self::PHP_EXTENSION));
        $class = $this->namespacePrefix . strtr($relative, \DIRECTORY_SEPARATOR, '\\');

        /** @var class-string $class */
        return $class;
    }
}
