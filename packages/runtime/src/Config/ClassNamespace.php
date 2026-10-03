<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Runtime\Exception\ConfigurationException;
use Stringable;

final readonly class ClassNamespace implements Stringable
{
    public string $value;

    /** @throws ConfigurationException */
    public function __construct(string $value)
    {
        $namespace = trim($value, '\\');

        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $namespace) !== 1) {
            throw ConfigurationException::valueInvalid($value, 'a PHP namespace such as "App\Generated"');
        }

        $this->value = $namespace;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
