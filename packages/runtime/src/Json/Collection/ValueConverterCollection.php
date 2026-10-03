<?php

declare(strict_types=1);

namespace Stewart\Runtime\Json\Collection;

use Stewart\Contracts\Collection\KeyedCollection;
use Stewart\Runtime\Json\ValueConverter;

/** @extends KeyedCollection<string, ValueConverter> */
final readonly class ValueConverterCollection extends KeyedCollection
{
    /** @param iterable<ValueConverter> $converters */
    public static function keyedByHandledClass(iterable $converters): self
    {
        return self::keyedBy($converters, static fn(ValueConverter $converter): string => $converter->handledClass());
    }

    public function findForClass(string $class): ?ValueConverter
    {
        return $this->elementAt($class);
    }
}
