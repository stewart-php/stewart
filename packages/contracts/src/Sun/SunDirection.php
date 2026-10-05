<?php

declare(strict_types=1);

namespace Stewart\Contracts\Sun;

enum SunDirection: string
{
    case Rising = 'rising';
    case Setting = 'setting';
}
