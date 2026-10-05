<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use Stewart\Contracts\Exception\JsonShapeException;
use Stewart\Contracts\Exception\TriggerException;
use Stewart\Contracts\Trigger\TriggerSpec;
use Stewart\Runtime\Json\ValueConverter;

final readonly class TriggerSpecConverter implements ValueConverter
{
    public function handledClass(): string
    {
        return TriggerSpec::class;
    }

    public function keySuffix(): string
    {
        return '';
    }

    /** @return array{triggers: list<array<string, mixed>>, variables: array<string, mixed>} */
    public function encodeValue(object $value): array
    {
        \assert($value instanceof TriggerSpec);

        return ['triggers' => $value->listTriggerConfigs(), 'variables' => $value->variables];
    }

    /** @throws JsonShapeException */
    public function decodeValue(mixed $value, string $path): TriggerSpec
    {
        if (!\is_array($value)) {
            throw JsonShapeException::wrongType($path, 'an object', get_debug_type($value));
        }

        $triggers = $value['triggers'] ?? null;
        $variables = $value['variables'] ?? null;

        if (!\is_array($triggers) || !array_is_list($triggers)) {
            throw JsonShapeException::wrongType($path . '.triggers', 'a list', get_debug_type($triggers));
        }

        if (!\is_array($variables)) {
            throw JsonShapeException::wrongType($path . '.variables', 'an object', get_debug_type($variables));
        }

        try {
            return TriggerSpec::fromSpec($triggers, $variables);
        } catch (TriggerException $e) {
            throw JsonShapeException::unexpectedValue($path, 'valid Home Assistant triggers', $e->getMessage());
        }
    }
}
