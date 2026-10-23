<?php

declare(strict_types=1);

namespace Stewart\Codegen\Action;

use Stewart\Codegen\Snapshot\RawMap;

final readonly class ActionTargetSpec
{
    /** @param list<string> $entityDomains */
    private function __construct(
        public bool $isTargetable,
        public bool $acceptsEntities,
        public array $entityDomains,
    ) {}

    private static function none(): self
    {
        return new self(false, false, []);
    }

    public static function fromRaw(RawMap $action): self
    {
        if (!$action->hasKey('target')) {
            return self::none();
        }

        $target = $action->readMap('target');

        if (!$target->hasKey('entity')) {
            return new self(true, false, []);
        }

        $domains = [];

        foreach ($target->listMaps('entity') as $selector) {
            foreach ($selector->listStrings('domain') as $domain) {
                $domains[$domain] = true;
            }
        }

        ksort($domains);

        return new self(true, true, array_keys($domains));
    }

    public function acceptsDomain(string $domain): bool
    {
        return $this->acceptsEntities && ($this->entityDomains === [] || \in_array($domain, $this->entityDomains, true));
    }
}
