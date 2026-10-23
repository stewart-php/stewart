<?php

declare(strict_types=1);

namespace Stewart\Contracts\Service;

use Stewart\Contracts\Exception\ServiceCallError;
use Stewart\Contracts\Exception\ServiceCallException;
use Stringable;

final readonly class ActionName implements Stringable
{
    private const string PATTERN = '/\A([a-z0-9_]+)\.([a-z0-9_]+)\z/';

    public string $value;

    public string $domain;

    public string $action;

    /** @throws ServiceCallException */
    public function __construct(string $value)
    {
        if (preg_match(self::PATTERN, $value, $parts) !== 1) {
            throw ServiceCallException::createForReason(ServiceCallError::ActionNameInvalid, action: $value);
        }

        $this->value = $value;
        $this->domain = $parts[1];
        $this->action = $parts[2];
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
