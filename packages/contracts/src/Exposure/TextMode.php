<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

enum TextMode: string
{
    case Text = 'text';
    case Password = 'password';
}
