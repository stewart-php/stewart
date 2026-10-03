<?php

declare(strict_types=1);

namespace Stewart\Runtime\Json;

enum ValueKind
{
    case String;
    case Int;
    case Float;
    case Bool;
    case Json;
    case Enum;
    case Dto;
    case Converted;
    case List;
    case Map;
    case Fragment;
}
