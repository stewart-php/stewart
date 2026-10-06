<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\DeviceId;
use Stewart\Contracts\Registry\FloorId;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Service\ServiceTarget;

#[CoversClass(ServiceTarget::class)]
final class ServiceTargetTest extends TestCase
{
    public function testRegistryTargetsAcceptTypedIds(): void
    {
        self::assertSame(['area_id' => ['kitchen', 'hall']], ServiceTarget::forAreas(new AreaId('kitchen'), 'hall')->toArray());
        self::assertSame(['floor_id' => ['up']], ServiceTarget::forFloors(new FloorId('up'))->toArray());
        self::assertSame(['label_id' => ['night']], ServiceTarget::forLabels(new LabelId('night'))->toArray());
        self::assertSame(['device_id' => ['d1']], ServiceTarget::forDevices(new DeviceId('d1'))->toArray());
    }
}
