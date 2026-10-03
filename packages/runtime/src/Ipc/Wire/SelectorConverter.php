<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use Stewart\Contracts\Exception\JsonShapeException;
use Stewart\Contracts\Exception\SelectorException;
use Stewart\Contracts\Selector\Selector;
use Stewart\Contracts\Selector\SelectorKind;
use Stewart\Runtime\Json\ValueConverter;
use Stewart\Support\Json\JsonShape;

final readonly class SelectorConverter implements ValueConverter
{
    public function handledClass(): string
    {
        return Selector::class;
    }

    public function keySuffix(): string
    {
        return '';
    }

    /** @return array<string, mixed> */
    public function encodeValue(object $value): array
    {
        \assert($value instanceof Selector);

        if ($value->isAny()) {
            return ['kind' => 'any'];
        }

        return match ($value->getKind()) {
            SelectorKind::Exact => ['kind' => 'exact', 'value' => $value->getPattern()],
            SelectorKind::Glob => ['kind' => 'glob', 'pattern' => $value->getPattern()],
            SelectorKind::Regex => ['kind' => 'regex', 'regex' => $value->getPattern()],
            SelectorKind::MqttFilter => ['kind' => 'mqtt_filter', 'filter' => $value->getPattern()],
            SelectorKind::AnyOf => ['kind' => 'any_of', 'selectors' => $value->listMembers()->mapToList($this->encodeValue(...))],
        };
    }

    /** @throws JsonShapeException|SelectorException */
    public function decodeValue(mixed $value, string $path): Selector
    {
        if (!\is_array($value)) {
            throw JsonShapeException::wrongType($path, 'an object', get_debug_type($value));
        }

        $kind = JsonShape::requireString($value, 'kind');

        return match ($kind) {
            'exact' => Selector::exact(JsonShape::requireString($value, 'value')),
            'glob' => Selector::glob(JsonShape::requireString($value, 'pattern')),
            'regex' => Selector::regex(JsonShape::requireString($value, 'regex')),
            'mqtt_filter' => Selector::mqttFilter(JsonShape::requireString($value, 'filter')),
            'any' => Selector::any(),
            'any_of' => Selector::anyOf(...array_map(
                fn(array $selector): Selector => $this->decodeValue($selector, $path . '.selectors'),
                JsonShape::requireObjectList($value, 'selectors'),
            )),
            default => throw JsonShapeException::unexpectedValue($path . '.kind', 'a selector kind', $kind),
        };
    }
}
