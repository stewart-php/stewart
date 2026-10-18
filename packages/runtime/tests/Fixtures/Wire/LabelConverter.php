<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Wire;

use Stewart\Runtime\Json\ValueConverter;
use Stewart\Support\Json\JsonShape;

final readonly class LabelConverter implements ValueConverter
{
    public function handledClass(): string
    {
        return Label::class;
    }

    public function keySuffix(): string
    {
        return '';
    }

    /** @return array{t: string} */
    public function encodeValue(object $value): array
    {
        \assert($value instanceof Label);

        return ['t' => $value->text];
    }

    public function decodeValue(mixed $value, string $path): Label
    {
        \assert(\is_array($value));

        return new Label(JsonShape::requireString($value, 't'));
    }
}
