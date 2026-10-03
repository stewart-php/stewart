<?php

declare(strict_types=1);

namespace Stewart\Runtime\Container\Collection;

use Stewart\Contracts\Collection\ListCollection;
use Stewart\Runtime\Container\AppRegistration;

/** @extends ListCollection<AppRegistration> */
final readonly class AppRegistrationCollection extends ListCollection
{
    /** @param iterable<AppRegistration> $registrations */
    public static function fromRegistrations(iterable $registrations): self
    {
        return self::fromList($registrations);
    }
}
