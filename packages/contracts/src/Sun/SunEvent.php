<?php

declare(strict_types=1);

namespace Stewart\Contracts\Sun;

enum SunEvent: string
{
    public const float HORIZON_DEGREES = -0.833;
    private const float CIVIL_TWILIGHT_DEGREES = -6.0;
    private const float NAUTICAL_TWILIGHT_DEGREES = -12.0;
    private const float ASTRONOMICAL_TWILIGHT_DEGREES = -18.0;
    private const float GOLDEN_HOUR_UPPER_DEGREES = 6.0;
    private const float BLUE_HOUR_UPPER_DEGREES = -4.0;

    case AstronomicalDawn = 'astronomical_dawn';
    case NauticalDawn = 'nautical_dawn';
    case CivilDawn = 'civil_dawn';
    case BlueHourMorningStart = 'blue_hour_morning_start';
    case BlueHourMorningEnd = 'blue_hour_morning_end';
    case Sunrise = 'sunrise';
    case GoldenHourMorningEnd = 'golden_hour_morning_end';
    case SolarNoon = 'solar_noon';
    case GoldenHourEveningStart = 'golden_hour_evening_start';
    case Sunset = 'sunset';
    case BlueHourEveningStart = 'blue_hour_evening_start';
    case BlueHourEveningEnd = 'blue_hour_evening_end';
    case CivilDusk = 'civil_dusk';
    case NauticalDusk = 'nautical_dusk';
    case AstronomicalDusk = 'astronomical_dusk';

    public function findElevationCrossing(): ?SunElevationCrossing
    {
        return match ($this) {
            self::AstronomicalDawn => self::createRising(self::ASTRONOMICAL_TWILIGHT_DEGREES),
            self::NauticalDawn => self::createRising(self::NAUTICAL_TWILIGHT_DEGREES),
            self::CivilDawn, self::BlueHourMorningStart => self::createRising(self::CIVIL_TWILIGHT_DEGREES),
            self::BlueHourMorningEnd => self::createRising(self::BLUE_HOUR_UPPER_DEGREES),
            self::Sunrise => self::createRising(self::HORIZON_DEGREES),
            self::GoldenHourMorningEnd => self::createRising(self::GOLDEN_HOUR_UPPER_DEGREES),
            self::SolarNoon => null,
            self::GoldenHourEveningStart => self::createSetting(self::GOLDEN_HOUR_UPPER_DEGREES),
            self::Sunset => self::createSetting(self::HORIZON_DEGREES),
            self::BlueHourEveningStart => self::createSetting(self::BLUE_HOUR_UPPER_DEGREES),
            self::BlueHourEveningEnd, self::CivilDusk => self::createSetting(self::CIVIL_TWILIGHT_DEGREES),
            self::NauticalDusk => self::createSetting(self::NAUTICAL_TWILIGHT_DEGREES),
            self::AstronomicalDusk => self::createSetting(self::ASTRONOMICAL_TWILIGHT_DEGREES),
        };
    }

    public function describe(): string
    {
        return str_replace('_', ' ', $this->value);
    }

    private static function createRising(float $elevationDegrees): SunElevationCrossing
    {
        return new SunElevationCrossing($elevationDegrees, SunDirection::Rising);
    }

    private static function createSetting(float $elevationDegrees): SunElevationCrossing
    {
        return new SunElevationCrossing($elevationDegrees, SunDirection::Setting);
    }
}
