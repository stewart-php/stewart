<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use SensitiveParameter;

final readonly class EnvironmentVariables
{
    /** @param array<string, string> $variables */
    public function __construct(#[SensitiveParameter] private array $variables = []) {}

    public static function fromGlobals(): self
    {
        $variables = getenv();

        /** @var mixed $value */
        foreach ($_ENV as $key => $value) {
            if (\is_string($value)) {
                $variables[(string) $key] = $value;
            }
        }

        return new self($variables);
    }

    public function get(string $name): ?string
    {
        return $this->variables[$name] ?? null;
    }

    /** @return array<string, string> */
    public function filterByPrefix(string $prefix): array
    {
        $matching = array_filter(
            $this->variables,
            static fn(string $value, int|string $name): bool => $value !== '' && $name !== $prefix && str_starts_with((string) $name, $prefix),
            \ARRAY_FILTER_USE_BOTH,
        );
        ksort($matching);

        return $matching;
    }
}
