<?php

declare(strict_types=1);

namespace Stewart\Client\Tests\Unit\Registry;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Client\Registry\RegistryDecoder;
use Stewart\Client\Registry\RegistryRow;

#[CoversClass(RegistryDecoder::class)]
#[CoversClass(RegistryRow::class)]
final class RegistryDecoderTest extends TestCase
{
    public function testDecodesAreaRow(): void
    {
        $area = new RegistryDecoder()->decodeAreaOrSkip([
            'area_id' => 'kitchen',
            'name' => 'Kitchen',
            'floor_id' => 'ground',
            'aliases' => ['Cooking', '', 3],
            'labels' => ['night'],
            'icon' => 'mdi:stove',
        ]);

        self::assertNotNull($area);
        self::assertSame('ground', $area->floorId?->value);
        self::assertSame(['Cooking'], $area->aliases);
        self::assertSame(['night'], $area->listLabelIds()->toStrings());
        self::assertSame('mdi:stove', $area->icon);
    }

    public function testDecodesFloorLabelAndDeviceRows(): void
    {
        $decoder = new RegistryDecoder();

        self::assertSame(-1, $decoder->decodeFloorOrSkip(['floor_id' => 'cellar', 'name' => 'Cellar', 'level' => -1])?->level);
        self::assertSame('red', $decoder->decodeLabelOrSkip(['label_id' => 'alarm', 'name' => 'Alarm', 'color' => 'red'])?->color);

        $device = $decoder->decodeDeviceOrSkip(['id' => 'abc', 'name' => 'Bulb', 'name_by_user' => null, 'area_id' => '', 'manufacturer' => 'Signify']);

        self::assertNotNull($device);
        self::assertSame('Bulb', $device->getDisplayName());
        self::assertNull($device->areaId);
        self::assertSame('Signify', $device->manufacturer);
    }

    public function testSkipsRowsWithoutId(): void
    {
        $decoder = new RegistryDecoder();

        self::assertNull($decoder->decodeAreaOrSkip(['name' => 'Nowhere']));
        self::assertNull($decoder->decodeFloorOrSkip(['floor_id' => '']));
        self::assertNull($decoder->decodeLabelOrSkip(['label_id' => null]));
        self::assertNull($decoder->decodeDeviceOrSkip([]));
    }

    public function testMissingNameFallsBackToId(): void
    {
        self::assertSame('kitchen', new RegistryDecoder()->decodeAreaOrSkip(['area_id' => 'kitchen'])?->name);
    }
}
