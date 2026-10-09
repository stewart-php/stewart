<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

enum BinarySensorDeviceClass: string
{
    case Battery = 'battery';
    case BatteryCharging = 'battery_charging';
    case CarbonMonoxide = 'carbon_monoxide';
    case Cold = 'cold';
    case Connectivity = 'connectivity';
    case Door = 'door';
    case GarageDoor = 'garage_door';
    case Gas = 'gas';
    case Heat = 'heat';
    case Light = 'light';
    case Lock = 'lock';
    case Moisture = 'moisture';
    case Motion = 'motion';
    case Moving = 'moving';
    case Occupancy = 'occupancy';
    case Opening = 'opening';
    case Plug = 'plug';
    case Power = 'power';
    case Presence = 'presence';
    case Problem = 'problem';
    case Running = 'running';
    case Safety = 'safety';
    case Smoke = 'smoke';
    case Sound = 'sound';
    case Tamper = 'tamper';
    case Update = 'update';
    case Vibration = 'vibration';
    case Window = 'window';
}
