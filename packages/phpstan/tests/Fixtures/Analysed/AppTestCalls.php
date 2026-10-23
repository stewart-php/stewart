<?php

declare(strict_types=1);

namespace Stewart\PHPStan\Tests\Fixtures\Analysed;

use PHPUnit\Framework\TestCase;
use Stewart\Contracts\HaContext;

final class AppTestCalls extends TestCase
{
    public function seedMadeUpEntities(HaContext $ha): void
    {
        $ha->getState('binary_sensor.made_up');
        $ha->callAction('script.made_up');
    }
}
