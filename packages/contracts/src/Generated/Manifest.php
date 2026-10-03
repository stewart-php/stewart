<?php

declare(strict_types=1);

namespace Stewart\Contracts\Generated;

interface Manifest
{
    /** @return list<string> */
    public static function listEntityIds(): array;

    /** @return list<string> */
    public static function listIgnoredEntityIds(): array;
}
