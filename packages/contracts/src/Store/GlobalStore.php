<?php

declare(strict_types=1);

namespace Stewart\Contracts\Store;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class GlobalStore {}
