<?php

declare(strict_types=1);

namespace Stewart\Store\Collection;

use Stewart\Contracts\Collection\KeyedCollection;
use Stewart\Store\StoreBackendFactory;

/** @extends KeyedCollection<string, StoreBackendFactory> */
final readonly class StoreBackendFactoryCollection extends KeyedCollection
{
    /** @param iterable<StoreBackendFactory> $factories */
    public static function keyedByScheme(iterable $factories): self
    {
        $factoriesByScheme = [];

        foreach ($factories as $factory) {
            foreach ($factory->listSchemes() as $scheme) {
                $factoriesByScheme[strtolower($scheme)] = $factory;
            }
        }

        return self::fromElementsByKey($factoriesByScheme);
    }

    public function find(string $scheme): ?StoreBackendFactory
    {
        return $this->elementAt(strtolower($scheme));
    }

    /** @return list<string> */
    public function listSchemes(): array
    {
        return array_map(strval(...), array_keys($this->elements()));
    }
}
