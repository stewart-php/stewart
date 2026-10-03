<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use Stewart\Contracts\Exception\JsonShapeException;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Runtime\Json\ValueConverter;
use Stewart\Runtime\Model\ExceptionDetails;
use Stewart\Support\Json\JsonShape;

/** @phpstan-import-type ExceptionContext from StewartException */
final readonly class ExceptionDetailsConverter implements ValueConverter
{
    public function handledClass(): string
    {
        return ExceptionDetails::class;
    }

    public function keySuffix(): string
    {
        return '';
    }

    /** @return array{class: string, reason: string, context: object|null} */
    public function encodeValue(object $value): array
    {
        \assert($value instanceof ExceptionDetails);

        return [
            'class' => $value->class,
            'reason' => $value->reason,
            'context' => $value->context === null ? null : (object) $value->context,
        ];
    }

    /** @throws JsonShapeException */
    public function decodeValue(mixed $value, string $path): ExceptionDetails
    {
        if (!\is_array($value)) {
            throw JsonShapeException::wrongType($path, 'an object', get_debug_type($value));
        }

        return new ExceptionDetails(
            class: JsonShape::requireString($value, 'class'),
            reason: JsonShape::requireString($value, 'reason'),
            context: $this->decodeContext($value, $path . '.context'),
        );
    }

    /**
     * @param array<array-key, mixed> $details
     * @return ExceptionContext|null
     * @throws JsonShapeException
     */
    private function decodeContext(array $details, string $path): ?array
    {
        if (($details['context'] ?? null) === null) {
            return null;
        }

        $context = [];

        foreach (JsonShape::requireObject($details, 'context') as $name => $value) {
            $context[$name] = match (true) {
                $value === null, \is_scalar($value) => $value,
                \is_array($value) && array_is_list($value) => $this->decodeScalarList($path . '.' . $name, $value),
                default => throw JsonShapeException::wrongType($path . '.' . $name, 'a scalar, null or a list of them', get_debug_type($value)),
            };
        }

        return $context;
    }

    /**
     * @param list<mixed> $values
     * @return list<scalar|null>
     * @throws JsonShapeException
     */
    private function decodeScalarList(string $path, array $values): array
    {
        $scalars = [];

        foreach ($values as $value) {
            $scalars[] = $value === null || \is_scalar($value)
                ? $value
                : throw JsonShapeException::wrongType($path, 'a list of scalars', get_debug_type($value));
        }

        return $scalars;
    }
}
