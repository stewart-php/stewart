<?php

declare(strict_types=1);

namespace Stewart\Codegen\Attribute;

use Stewart\Contracts\State\Collection\EntityStateCollection;

final readonly class AttributeKindInference
{
    /** @return array<string, AttributeKind> */
    public function inferKindsByAttributeName(EntityStateCollection $states): array
    {
        /** @var array<string, AttributeKind|null> $kinds */
        $kinds = [];

        foreach ($states as $state) {
            // Unavailable entities report incomplete attributes.
            if ($state->isUnavailable()) {
                continue;
            }

            foreach ($state->attributes as $name => $value) {
                $name = (string) $name;
                $observed = AttributeKind::ofValue($value);
                $known = $kinds[$name] ?? null;
                $kinds[$name] = $observed === null ? $known : $known?->widen($observed) ?? $observed;
            }
        }

        ksort($kinds, \SORT_STRING);

        return array_map(static fn(?AttributeKind $kind): AttributeKind => $kind ?? AttributeKind::Mixed, $kinds);
    }
}
