<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

enum EntityCategory: string
{
    case Config = 'config';
    case Diagnostic = 'diagnostic';
}
