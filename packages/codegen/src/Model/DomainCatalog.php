<?php

declare(strict_types=1);

namespace Stewart\Codegen\Model;

final readonly class DomainCatalog
{
    // Fixed, not inferred: a light that is off in every snapshot still needs isOn().
    private const array ON_OFF = [
        'automation', 'binary_sensor', 'fan', 'group', 'humidifier', 'input_boolean',
        'light', 'remote', 'script', 'siren', 'switch', 'update',
    ];

    private const array NUMERIC = ['counter', 'input_number', 'number', 'sensor'];

    public function getTraitsForDomain(string $domain): DomainTraits
    {
        return new DomainTraits(
            onOff: \in_array($domain, self::ON_OFF, true),
            numeric: \in_array($domain, self::NUMERIC, true),
        );
    }
}
