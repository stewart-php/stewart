<?php

declare(strict_types=1);

namespace Stewart\Codegen\Output;

enum ShrinkPolicy
{
    case Refuse;
    case Allow;
}
