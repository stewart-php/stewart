<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Action;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Action\ActionTarget;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\DeviceId;
use Stewart\Contracts\Registry\FloorId;
use Stewart\Contracts\Registry\LabelId;

#[CoversClass(ActionTarget::class)]
final class ActionTargetTest extends TestCase
{
    public function testRegistryTargetsAcceptTypedIds(): void
    {
        self::assertSame(['area_id' => ['kitchen', 'hall']], ActionTarget::forAreas(new AreaId('kitchen'), 'hall')->toArray());
        self::assertSame(['floor_id' => ['up']], ActionTarget::forFloors(new FloorId('up'))->toArray());
        self::assertSame(['label_id' => ['night']], ActionTarget::forLabels(new LabelId('night'))->toArray());
        self::assertSame(['device_id' => ['d1']], ActionTarget::forDevices(new DeviceId('d1'))->toArray());
    }
}
