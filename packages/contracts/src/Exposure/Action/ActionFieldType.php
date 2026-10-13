<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure\Action;

enum ActionFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
    case Select = 'select';
    case Entity = 'entity';
}
