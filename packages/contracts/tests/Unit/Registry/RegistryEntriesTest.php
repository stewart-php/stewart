<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Registry\Area;
use Stewart\Contracts\Registry\AreaId;
use Stewart\Contracts\Registry\Device;
use Stewart\Contracts\Registry\DeviceId;
use Stewart\Contracts\Registry\Floor;
use Stewart\Contracts\Registry\FloorId;
use Stewart\Contracts\Registry\Label;
use Stewart\Contracts\Registry\LabelId;
use Stewart\Contracts\Registry\RegisteredEntity;
use Stewart\Contracts\Registry\RegistryNames;

#[CoversClass(Area::class)]
#[CoversClass(Floor::class)]
#[CoversClass(Label::class)]
#[CoversClass(Device::class)]
#[CoversClass(RegisteredEntity::class)]
#[CoversClass(RegistryNames::class)]
final class RegistryEntriesTest extends TestCase
{
    public function testAreaMatchesNameOrAliasIgnoringCase(): void
    {
        $area = new Area(new AreaId('kitchen'), 'Kitchen', aliases: ['Cooking corner']);

        self::assertTrue($area->isNamed('kitchen'));
        self::assertTrue($area->isNamed(' cooking CORNER '));
        self::assertFalse($area->isNamed('Hall'));
    }

    public function testFloorAndLabelMatchByName(): void
    {
        self::assertTrue(new Floor(new FloorId('upstairs'), 'Upstairs', 1, ['First floor'])->isNamed('first floor'));
        self::assertTrue(new Label(new LabelId('night'), 'Night')->isNamed('NIGHT'));
    }

    public function testDeviceDisplayNamePrefersUserName(): void
    {
        self::assertSame('Hall lamp', new Device(new DeviceId('d1'), 'Hue bulb', 'Hall lamp')->getDisplayName());
        self::assertSame('Hue bulb', new Device(new DeviceId('d1'), 'Hue bulb')->getDisplayName());
        self::assertTrue(new Device(new DeviceId('d1'), disabledBy: 'user')->isDisabled());
    }

    public function testEntityReportsFlagsAndLabels(): void
    {
        $entity = new RegisteredEntity(
            new EntityId('sensor.hall_battery'),
            labelIds: [new LabelId('night'), new LabelId('battery')],
            entityCategory: 'diagnostic',
            hiddenBy: 'user',
        );

        self::assertTrue($entity->isHidden());
        self::assertFalse($entity->isDisabled());
        self::assertTrue($entity->hasEntityCategory());
        self::assertSame(['night', 'battery'], $entity->listLabelIds()->toStrings());
    }
}
