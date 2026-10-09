<?php

declare(strict_types=1);

namespace Stewart\Client\Component;

use Stewart\Client\Exception\HaClientException;

final readonly class ComponentVersion
{
    public function __construct(
        public string $componentVersion,
        public int $protocol,
    ) {}

    /**
     * @param array<array-key, mixed> $result
     * @throws HaClientException
     */
    public static function fromVersionResult(array $result): self
    {
        $componentVersion = $result['component_version'] ?? null;
        $protocol = $result['protocol'] ?? null;

        if (!\is_string($componentVersion) || !\is_int($protocol)) {
            throw HaClientException::protocolViolation('a stewart/version result without component_version and protocol');
        }

        return new self($componentVersion, $protocol);
    }

    public function isCompatible(): bool
    {
        return $this->protocol === ComponentProtocol::VERSION;
    }
}
