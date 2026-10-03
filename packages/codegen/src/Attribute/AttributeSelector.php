<?php

declare(strict_types=1);

namespace Stewart\Codegen\Attribute;

use Stewart\Codegen\Attribute\Collection\UnseenAttributeCollection;
use Stewart\Codegen\Php\Identifier;

final readonly class AttributeSelector
{
    private const string FRIENDLY_NAME = 'friendly_name';

    public function __construct(private AttributeCatalog $attributeCatalog) {}

    /** @param array<string, AttributeKind> $observed */
    public function selectAttributes(string $domain, array $observed, AttributeFilter $filter): AttributeSelection
    {
        $typed = $this->attributeCatalog->listKindsForDomain($domain);

        foreach ($observed as $key => $observedKind) {
            $key = (string) $key;
            $kind = $this->attributeCatalog->findKind($domain, $key) ?? ($filter->includes($key) ? $observedKind : null);

            if ($kind !== null) {
                $typed[$key] = $kind;
            }
        }

        $unseen = [];

        foreach ($filter->include->exactEntries() as $key) {
            if (!isset($observed[$key])) {
                $typed[$key] ??= $this->attributeCatalog->findKind($domain, $key) ?? AttributeKind::Mixed;
                $unseen[] = new UnseenAttribute($domain, $key);
            }
        }

        $typed = array_filter($typed, fn(string|int $key): bool => $this->isTypeable((string) $key, $filter), \ARRAY_FILTER_USE_KEY);
        ksort($typed, \SORT_STRING);

        return new AttributeSelection($typed, UnseenAttributeCollection::fromAttributes($unseen));
    }

    // friendly_name has its own accessor; an include glob can match keys that are not identifiers.
    private function isTypeable(string $key, AttributeFilter $filter): bool
    {
        return $key !== self::FRIENDLY_NAME && Identifier::startsWithLetter($key) && !$filter->excludes($key);
    }
}
