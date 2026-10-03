<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Stewart\Runtime\Exception\ConfigurationException;
use Stringable;

final readonly class ProjectDirectory implements Stringable
{
    public string $value;

    /** @throws ConfigurationException */
    public function __construct(string $value)
    {
        $directory = rtrim($value, '/');

        // Restricted to the project: the result goes to a writer that deletes files.
        if ($directory === '' || str_starts_with($directory, '/') || \in_array('..', explode('/', $directory), true)) {
            throw ConfigurationException::valueInvalid($value, 'a path inside the project, such as "generated"');
        }

        $this->value = $directory;
    }

    public function resolveWithin(string $projectRoot): string
    {
        return $projectRoot . '/' . $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
