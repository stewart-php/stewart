<?php

declare(strict_types=1);

namespace Stewart\Contracts\Registry\Collection;

use Stewart\Contracts\Collection\KeyedCollection;
use Stewart\Contracts\Registry\Device;
use Stewart\Contracts\Registry\DeviceId;

/** @extends KeyedCollection<string, Device> */
final readonly class DeviceCollection extends KeyedCollection
{
    /** @param iterable<Device> $entries */
    public static function keyedByDeviceId(iterable $entries): self
    {
        return self::keyedBy($entries, static fn(Device $entry): string => $entry->deviceId->value);
    }

    public function find(DeviceId $deviceId): ?Device
    {
        return $this->elementAt($deviceId->value);
    }
}
