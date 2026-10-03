<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Runtime\Exception\ConfigurationException;

final readonly class SecretFileReader
{
    private const string TRAILING_LINE_BREAK = '/\r?\n\z/';

    /** @throws ConfigurationException */
    public function readSecretFile(string $variable, string $path): string
    {
        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw ConfigurationException::environmentSecretFileUnreadable($variable, $path);
        }

        return preg_replace(self::TRAILING_LINE_BREAK, '', $contents, 1) ?? $contents;
    }
}
