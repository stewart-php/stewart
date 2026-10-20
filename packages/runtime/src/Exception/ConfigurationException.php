<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Exception\StewartException;

/** @extends StewartException<ConfigurationError> */
final class ConfigurationException extends StewartException
{
    public static function valueInvalid(string $value, string $expected): self
    {
        return self::createForReason(ConfigurationError::ValueInvalid, value: $value, expected: $expected);
    }

    public static function workerIndexOutOfRange(AppId $appId, int $worker, int $workers): self
    {
        return self::createForReason(
            ConfigurationError::WorkerIndexOutOfRange,
            appId: $appId->value,
            worker: $worker,
            workers: $workers,
            highestIndex: $workers - 1,
        );
    }

    public static function appSelectionUnknown(AppId $appId, AppIdCollection $knownAppIds): self
    {
        return self::createForReasonWithAppendedText(
            ConfigurationError::AppSelectionUnknown,
            ['appId' => $appId->value, 'knownAppIds' => $knownAppIds->toStrings()],
            self::describeKnownApps($knownAppIds),
        );
    }

    public static function environmentVariableUnknown(string $variable, ?string $suggestion): self
    {
        return self::createForReasonWithAppendedText(
            ConfigurationError::EnvironmentVariableUnknown,
            ['variable' => $variable, 'suggestion' => $suggestion],
            $suggestion === null ? null : \sprintf('Did you mean %s?', $suggestion),
        );
    }

    private static function describeKnownApps(AppIdCollection $knownAppIds): string
    {
        return $knownAppIds->isEmpty()
            ? 'No automations were found; check the #[Automation] attribute.'
            : 'Known automations: ' . implode(', ', $knownAppIds->toStrings()) . '.';
    }
}
