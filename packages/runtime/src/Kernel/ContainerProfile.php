<?php

declare(strict_types=1);

namespace Stewart\Runtime\Kernel;

enum ContainerProfile: string
{
    case Console = 'console';
    case Broker = 'broker';
    case Worker = 'worker';

    /** @return list<string> */
    public function configFiles(): array
    {
        return match ($this) {
            self::Console => ['control.php', 'console.php'],
            self::Broker => ['common.php', 'control.php', 'broker.php'],
            self::Worker => ['common.php', 'worker.php'],
        };
    }

    /** @return list<string> */
    public function optionalPackages(): array
    {
        return match ($this) {
            self::Console => ['stewart-php/codegen'],
            self::Broker => ['stewart-php/store-redis', 'stewart-php/mqtt'],
            self::Worker => ['stewart-php/store-redis'],
        };
    }
}
