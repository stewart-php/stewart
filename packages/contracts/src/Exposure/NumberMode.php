<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

enum NumberMode: string
{
    case Auto = 'auto';
    case Box = 'box';
    case Slider = 'slider';
}
