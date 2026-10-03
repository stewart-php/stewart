<?php

declare(strict_types=1);

namespace Stewart\Contracts\Service;

interface ServiceTargetSource
{
    public function toServiceTarget(): ServiceTarget;
}
