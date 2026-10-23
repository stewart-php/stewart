<?php

declare(strict_types=1);

namespace Stewart\Contracts\Generated;

interface ActionManifest extends Manifest
{
    /** @return list<string> */
    public static function listActionNames(): array;
}
