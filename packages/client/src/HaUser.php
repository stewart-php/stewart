<?php

declare(strict_types=1);

namespace Stewart\Client;

use Stewart\Client\Exception\HaClientException;

final readonly class HaUser
{
    public function __construct(
        public string $id,
        public ?string $name,
        public bool $isAdmin,
    ) {}

    /**
     * @param array<array-key, mixed> $result
     * @throws HaClientException
     */
    public static function fromCurrentUserResult(array $result): self
    {
        $id = $result['id'] ?? null;
        $name = $result['name'] ?? null;

        if (!\is_string($id) || $id === '') {
            throw HaClientException::protocolViolation('a current user without an id');
        }

        return new self($id, \is_string($name) ? $name : null, ($result['is_admin'] ?? false) === true);
    }
}
