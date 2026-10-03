<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command;

use SensitiveParameter;

final readonly class Authenticate
{
    public function __construct(#[SensitiveParameter] private string $accessToken) {}

    /** @return array<string, string> */
    public function toMessage(): array
    {
        return ['type' => 'auth', 'access_token' => $this->accessToken];
    }
}
