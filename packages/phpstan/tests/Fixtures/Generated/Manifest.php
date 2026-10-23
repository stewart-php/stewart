<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Tests\Fixtures\Generated;

use Stewart\Contracts\Generated\ActionManifest;

final class Manifest implements ActionManifest
{
    public static function listEntityIds(): array
    {
        return ['light.hall', 'light.kitchen', 'switch.fan', 'zone.home'];
    }

    public static function listIgnoredEntityIds(): array
    {
        return ['light.attic'];
    }

    public static function listActionNames(): array
    {
        return ['light.turn_off', 'light.turn_on', 'notify.notify', 'switch.turn_on'];
    }
}
