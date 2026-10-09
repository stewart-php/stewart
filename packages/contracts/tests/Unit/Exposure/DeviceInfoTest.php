<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Unit\Exposure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\ExposureError;
use Stewart\Contracts\Exposure\DeviceInfo;
use Stewart\Testing\Exception\AssertsReason;

#[CoversClass(DeviceInfo::class)]
final class DeviceInfoTest extends TestCase
{
    use AssertsReason;

    public function testInvalidIdentifierIsRejected(): void
    {
        self::assertThrowsReason(ExposureError::ConfigInvalid, static fn() => new DeviceInfo('Greenhouse', 'Greenhouse'));
    }

    public function testEmptyNameIsRejected(): void
    {
        self::assertThrowsReason(ExposureError::ConfigInvalid, static fn() => new DeviceInfo('greenhouse', ''));
    }
}
