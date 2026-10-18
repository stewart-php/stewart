<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use Stewart\Contracts\State\EventContext;
use Stewart\Runtime\Json\ValueConverter;
use Stewart\Support\Exception\JsonShapeException;

final readonly class EventContextConverter implements ValueConverter
{
    public function handledClass(): string
    {
        return EventContext::class;
    }

    public function keySuffix(): string
    {
        return '';
    }

    /** @return array{id: string, parent_id: string|null, user_id: string|null} */
    public function encodeValue(object $value): array
    {
        \assert($value instanceof EventContext);

        return ['id' => $value->id, 'parent_id' => $value->parentId, 'user_id' => $value->userId];
    }

    /** @throws JsonShapeException */
    public function decodeValue(mixed $value, string $path): EventContext
    {
        if (!\is_array($value)) {
            throw JsonShapeException::wrongType($path, 'an object', get_debug_type($value));
        }

        $id = $value['id'] ?? null;

        if (!\is_string($id)) {
            throw JsonShapeException::wrongType($path . '.id', 'a string', get_debug_type($id));
        }

        return new EventContext($id, $this->findString($value, 'parent_id', $path), $this->findString($value, 'user_id', $path));
    }

    /**
     * @param array<array-key, mixed> $context
     * @throws JsonShapeException
     */
    private function findString(array $context, string $key, string $path): ?string
    {
        $value = $context[$key] ?? null;

        return $value === null || \is_string($value) ? $value : throw JsonShapeException::wrongType($path . '.' . $key, 'a string', get_debug_type($value));
    }
}
