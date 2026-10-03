<?php

declare(strict_types=1);

namespace Stewart\Codegen\Service;

use Stewart\Codegen\Snapshot\RawMap;

final readonly class ServiceTargetSpec
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

    public static function fromRaw(RawMap $service): self
    {
        if (!$service->hasKey('target')) {
            return self::none();
        }

        $target = $service->readMap('target');

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
