<?php

declare(strict_types=1);

namespace Stewart\Runtime\Json;

enum ValueKind
{
    case String;
    case Int;
    case Float;
    case Number;
    case Bool;
    case Json;
    case Enum;
    case Dto;
    case Variant;
    case Converted;
    case List;
    case Map;
    case Fragment;
}
