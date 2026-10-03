<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config\Environment;

use Stewart\Runtime\Exception\ConfigurationException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class InlineValueInference
{
    public function isInlineCollection(string $raw): bool
    {
        $value = trim($raw);

        return str_starts_with($value, '[') || str_starts_with($value, '{');
    }

    /** @throws ConfigurationException */
    public function parseInlineCollection(string $name, string $raw): mixed
    {
        try {
            return Yaml::parse(trim($raw));
        } catch (ParseException $e) {
            throw ConfigurationException::environmentValueUnparsable($name, $e);
        }
    }

    public function inferValue(string $raw): mixed
    {
        $value = trim($raw);

        if ($this->isInlineCollection($value)) {
            try {
                return Yaml::parse($value);
            } catch (ParseException) {
                return $value;
            }
        }

        return match (true) {
            strtolower($value) === 'true' => true,
            strtolower($value) === 'false' => false,
            strtolower($value) === 'null', $value === '~' => null,
            $value === (string) (int) $value => (int) $value,
            is_numeric($value) && $value === (string) (float) $value => (float) $value,
            default => $value,
        };
    }
}
