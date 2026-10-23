<?php

declare(strict_types=1);

namespace Stewart\Contracts\Action;

interface ActionTargetSource
{
    public function toActionTarget(): ActionTarget;
}
