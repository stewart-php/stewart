<?php

declare(strict_types=1);

namespace Stewart\Support\Text;

/** @internal */
final readonly class OptionNameMatcher
{
    public function __construct(private ClosestNameFinder $names) {}

    /** @param list<string> $parameters */
    public function matchOption(string $option, array $parameters): OptionNameMatch
    {
        if (\in_array($option, $parameters, true)) {
            return OptionNameMatch::matched($option);
        }

        $folded = self::foldName($option);
        $matches = array_values(array_filter($parameters, static fn(string $parameter): bool => self::foldName($parameter) === $folded));

        return match (\count($matches)) {
            0 => OptionNameMatch::unmatched($this->names->findClosestName($option, $parameters)),
            1 => OptionNameMatch::matched($matches[0]),
            default => OptionNameMatch::ambiguous($matches),
        };
    }

    // Environment variable names lose case and word breaks, so MOTION_SENSOR has to reach $motionSensor.
    private static function foldName(string $name): string
    {
        return strtolower(str_replace(['_', '-'], '', $name));
    }
}
