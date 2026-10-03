<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Runtime\Exception\ConfigurationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class ConfigFileReader
{
    /**
     * @return array<array-key, mixed>
     * @throws ConfigurationException
     */
    public function readYamlMap(string $path): array
    {
        if (!is_file($path)) {
            throw ConfigurationException::configFileMissing($path);
        }

        try {
            $parsed = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw ConfigurationException::configFileUnparsable($path, $e);
        }

        if ($parsed === null) {
            return [];
        }

        if (!\is_array($parsed)) {
            throw ConfigurationException::configFileNotAMap($path, get_debug_type($parsed));
        }

        return $parsed;
    }
}
