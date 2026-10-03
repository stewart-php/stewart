<?php

declare(strict_types=1);

namespace Stewart\Store;

use JsonException;
use Stewart\Contracts\Exception\StoreException;
use Stewart\Contracts\Store\Storable;
use Stewart\Support\Json\JsonDecoder;
use Stewart\Support\Json\JsonEncoder;
use Stewart\Support\Json\JsonShape;
use Throwable;

final readonly class StoreValueCodec
{
    /** @throws StoreException */
    public function encodeValue(string $key, bool|int|float|string|Storable $value): string
    {
        if (!$value instanceof Storable) {
            return $this->encodeJson($key, $value);
        }

        $data = $value->toStorage();

        if ($data !== [] && array_is_list($data)) {
            throw StoreException::valueNotAMap($key, $value::class);
        }

        return $this->encodeJson($key, (object) $data);
    }

    /** @throws StoreException */
    public function decodeInt(string $key, string $raw): int
    {
        $value = $this->decodeJson($key, $raw);

        return \is_int($value) ? $value : throw $this->typeMismatch($key, 'an integer', $value);
    }

    /** @throws StoreException */
    public function decodeFloat(string $key, string $raw): float
    {
        $value = $this->decodeJson($key, $raw);

        return match (true) {
            \is_float($value) => $value,
            \is_int($value) => (float) $value,
            default => throw $this->typeMismatch($key, 'a number', $value),
        };
    }

    /** @throws StoreException */
    public function decodeString(string $key, string $raw): string
    {
        $value = $this->decodeJson($key, $raw);

        return \is_string($value) ? $value : throw $this->typeMismatch($key, 'a string', $value);
    }

    /** @throws StoreException */
    public function decodeBool(string $key, string $raw): bool
    {
        $value = $this->decodeJson($key, $raw);

        return \is_bool($value) ? $value : throw $this->typeMismatch($key, 'a boolean', $value);
    }

    /**
     * @template T of Storable
     *
     * @param class-string<T> $class
     * @return T
     * @throws StoreException
     */
    public function decodeObject(string $key, string $raw, string $class): Storable
    {
        // json_decode returns an array for both {} and []; objects are always written as {}.
        if (!str_starts_with(ltrim($raw), '{')) {
            throw $this->typeMismatch($key, 'an object', $this->decodeJson($key, $raw));
        }

        $data = $this->decodeJson($key, $raw);

        if (!\is_array($data)) {
            throw $this->typeMismatch($key, 'an object', $data);
        }

        try {
            return $class::fromStorage(JsonShape::treatKeysAsStrings($data));
        } catch (Throwable $e) {
            throw StoreException::valueRejected($key, $class, $e);
        }
    }

    /** @throws StoreException */
    private function decodeJson(string $key, string $raw): mixed
    {
        try {
            return JsonDecoder::decodeJson($raw);
        } catch (JsonException $e) {
            throw StoreException::valueMalformed($key, $e);
        }
    }

    /** @throws StoreException */
    private function encodeJson(string $key, mixed $value): string
    {
        try {
            return JsonEncoder::encodeToJson($value);
        } catch (JsonException $e) {
            throw StoreException::valueNotEncodable($key, $e);
        }
    }

    private function typeMismatch(string $key, string $expected, mixed $actual): StoreException
    {
        return StoreException::typeMismatch($key, $expected, $this->describeJsonType($actual));
    }

    private function describeJsonType(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            \is_bool($value) => 'a boolean',
            \is_int($value) => 'an integer',
            \is_float($value) => 'a float',
            \is_string($value) => 'a string',
            \is_array($value) && array_is_list($value) => 'a list',
            default => 'an object',
        };
    }
}
