<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker\Deploy;

use Stewart\Runtime\Exception\DeployException;
use Stringable;

final readonly class CommitId implements Stringable
{
    private const string HASH_PATTERN = '/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/';

    public string $value;

    /** @throws DeployException */
    public function __construct(string $value)
    {
        if (preg_match(self::HASH_PATTERN, $value) !== 1) {
            throw DeployException::commitIdInvalid($value);
        }

        $this->value = $value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
