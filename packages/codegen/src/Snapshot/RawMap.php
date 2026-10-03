<?php

declare(strict_types=1);

namespace Stewart\Codegen\Snapshot;

use Stewart\Codegen\Snapshot\Collection\RawMapCollection;

/** @internal */
final readonly class RawMap
{
    /** @param array<array-key, mixed> $values */
    private function __construct(private array $values) {}

    public static function fromValue(mixed $value): self
    {
        return new self(\is_array($value) ? $value : []);
    }

    public function hasKey(string $key): bool
    {
        return \array_key_exists($key, $this->values);
    }

    /** @return list<string> */
    public function listKeys(): array
    {
        return array_map(strval(...), array_keys($this->values));
    }

    public function readString(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    public function readBool(string $key): bool
    {
        return ($this->values[$key] ?? null) === true;
    }

    public function readScalar(string $key): bool|int|float|string|null
    {
        $value = $this->values[$key] ?? null;

        return \is_scalar($value) ? $value : null;
    }

    public function readNumber(string $key): int|float|null
    {
        $value = $this->values[$key] ?? null;

        return \is_int($value) || \is_float($value) ? $value : null;
    }

    public function readMap(string $key): self
    {
        return self::fromValue($this->values[$key] ?? null);
    }

    public function listMaps(string $key): RawMapCollection
    {
        $value = $this->values[$key] ?? null;

        if (!\is_array($value)) {
            return RawMapCollection::empty();
        }

        if (!array_is_list($value)) {
            return RawMapCollection::fromMaps([self::fromValue($value)]);
        }

        return RawMapCollection::fromMaps(array_map(self::fromValue(...), array_filter($value, \is_array(...))));
    }

    /** @return list<string> */
    public function listStrings(string $key): array
    {
        $value = $this->values[$key] ?? null;

        if (\is_string($value)) {
            return [$value];
        }

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, \is_string(...)));
    }
}
