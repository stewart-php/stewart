<?php

declare(strict_types=1);

namespace Stewart\Contracts\Action;

use Stewart\Contracts\Exception\ActionCallError;
use Stewart\Contracts\Exception\ActionCallException;
use Stringable;

final readonly class ActionName implements Stringable
{
    private const string PATTERN = '/\A([a-z0-9_]+)\.([a-z0-9_]+)\z/';

    public string $value;

    public string $domain;

    public string $action;

    /** @throws ActionCallException */
    public function __construct(string $value)
    {
        if (preg_match(self::PATTERN, $value, $parts) !== 1) {
            throw ActionCallException::createForReason(ActionCallError::ActionNameInvalid, action: $value);
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
