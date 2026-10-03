<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Stewart\Runtime\Model\ResourceScope;

final class AppActivityCounters
{
    /** @var array<string, AppActivity> */
    private array $activityByScope = [];

    public function findOrCreateActivityForScope(ResourceScope $scope): AppActivity
    {
        return $this->activityByScope[$scope->wireValue()] ??= new AppActivity();
    }
}
