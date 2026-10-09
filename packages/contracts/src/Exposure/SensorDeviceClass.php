<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exposure;

enum SensorDeviceClass: string
{
    case Date = 'date';
    case Enum = 'enum';
    case Timestamp = 'timestamp';
    case AbsoluteHumidity = 'absolute_humidity';
    case ApparentPower = 'apparent_power';
    case Aqi = 'aqi';
    case Area = 'area';
    case AtmosphericPressure = 'atmospheric_pressure';
    case Battery = 'battery';
    case BloodGlucoseConcentration = 'blood_glucose_concentration';
    case CarbonMonoxide = 'carbon_monoxide';
    case CarbonDioxide = 'carbon_dioxide';
    case Conductivity = 'conductivity';
    case Current = 'current';
    case DataRate = 'data_rate';
    case DataSize = 'data_size';
    case Distance = 'distance';
    case Duration = 'duration';
    case Energy = 'energy';
    case EnergyDistance = 'energy_distance';
    case EnergyStorage = 'energy_storage';
    case Frequency = 'frequency';
    case Gas = 'gas';
    case Humidity = 'humidity';
    case Illuminance = 'illuminance';
    case Irradiance = 'irradiance';
    case Moisture = 'moisture';
    case Monetary = 'monetary';
    case NitrogenDioxide = 'nitrogen_dioxide';
    case NitrogenMonoxide = 'nitrogen_monoxide';
    case NitrousOxide = 'nitrous_oxide';
    case Ozone = 'ozone';
    case Ph = 'ph';
    case Pm1 = 'pm1';
    case Pm10 = 'pm10';
    case Pm25 = 'pm25';
    case Pm4 = 'pm4';
    case PowerFactor = 'power_factor';
    case Power = 'power';
    case Precipitation = 'precipitation';
    case PrecipitationIntensity = 'precipitation_intensity';
    case Pressure = 'pressure';
    case ReactiveEnergy = 'reactive_energy';
    case ReactivePower = 'reactive_power';
    case SignalStrength = 'signal_strength';
    case SoundPressure = 'sound_pressure';
    case Speed = 'speed';
    case SulphurDioxide = 'sulphur_dioxide';
    case Temperature = 'temperature';
    case TemperatureDelta = 'temperature_delta';
    case VolatileOrganicCompounds = 'volatile_organic_compounds';
    case VolatileOrganicCompoundsParts = 'volatile_organic_compounds_parts';
    case Voltage = 'voltage';
    case Volume = 'volume';
    case VolumeStorage = 'volume_storage';
    case VolumeFlowRate = 'volume_flow_rate';
    case Water = 'water';
    case Weight = 'weight';
    case WindDirection = 'wind_direction';
    case WindSpeed = 'wind_speed';

    public function takesDateTime(): bool
    {
        return $this === self::Timestamp;
    }

    public function takesDate(): bool
    {
        return $this === self::Date;
    }
}
