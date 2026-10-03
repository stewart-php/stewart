<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\App\Collection\AppIdCollection;
use Stewart\Contracts\Exception\StewartException;
use Throwable;

/** @extends StewartException<ConfigurationError> */
final class ConfigurationException extends StewartException
{
    public static function schemaViolation(Throwable $previous): self
    {
        return self::createForReason(ConfigurationError::SchemaViolation, [], $previous);
    }

    public static function configFileMissing(string $path): self
    {
        return self::createForReason(ConfigurationError::ConfigFileMissing, ['path' => $path]);
    }

    public static function configFileUnparsable(string $path, Throwable $previous): self
    {
        return self::createForReason(ConfigurationError::ConfigFileUnparsable, ['path' => $path], $previous);
    }

    public static function configFileNotAMap(string $path, string $actualType): self
    {
        return self::createForReason(ConfigurationError::ConfigFileNotAMap, ['path' => $path, 'actualType' => $actualType]);
    }

    public static function valueInvalid(string $value, string $expected): self
    {
        return self::createForReason(ConfigurationError::ValueInvalid, ['value' => $value, 'expected' => $expected]);
    }

    public static function keyInvalid(string $path, string $expected): self
    {
        return self::createForReason(ConfigurationError::KeyInvalid, ['path' => $path, 'expected' => $expected]);
    }

    public static function keyParseFailed(string $path, Throwable $previous): self
    {
        return self::createForReason(ConfigurationError::KeyParseFailed, ['path' => $path], $previous);
    }

    public static function durationTooShort(string $path, string $value, string $minimum): self
    {
        return self::createForReason(ConfigurationError::DurationTooShort, ['path' => $path, 'value' => $value, 'minimum' => $minimum]);
    }

    public static function maxDelayBelowInitialDelay(string $path, string $value, string $initialDelayPath, string $initialDelay): self
    {
        return self::createForReason(
            ConfigurationError::MaxDelayBelowInitialDelay,
            ['path' => $path, 'value' => $value, 'initialDelayPath' => $initialDelayPath, 'initialDelay' => $initialDelay],
        );
    }

    public static function restartWindowTooShort(string $window, int $attempts, string $backoffTotal): self
    {
        return self::createForReason(ConfigurationError::RestartWindowTooShort, ['window' => $window, 'attempts' => $attempts, 'backoffTotal' => $backoffTotal]);
    }

    public static function homeAssistantMissing(): self
    {
        return self::createForReason(ConfigurationError::HomeAssistantMissing);
    }

    public static function placeholderUnset(string $setting, string $variable): self
    {
        return self::createForReason(ConfigurationError::PlaceholderUnset, ['setting' => $setting, 'variable' => $variable]);
    }

    public static function controlTokenMissing(): self
    {
        return self::createForReason(ConfigurationError::ControlTokenMissing);
    }

    public static function workerIndexOutOfRange(AppId $appId, int $worker, int $workers): self
    {
        return self::createForReason(
            ConfigurationError::WorkerIndexOutOfRange,
            ['appId' => $appId->value, 'worker' => $worker, 'workers' => $workers, 'highestIndex' => $workers - 1],
        );
    }

    public static function appNameMismatch(AppId $appId, AppId $automationId): self
    {
        return self::createForReason(ConfigurationError::AppNameMismatch, ['appId' => $appId->value, 'automationId' => $automationId->value]);
    }

    public static function appUnknown(AppId $appId, AppIdCollection $knownAppIds): self
    {
        return self::createForReasonWithAppendedText(
            ConfigurationError::AppUnknown,
            ['appId' => $appId->value, 'knownAppIds' => $knownAppIds->toStrings()],
            self::describeKnownApps($knownAppIds),
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
        $context = ['variable' => $variable, 'suggestion' => $suggestion];

        return $suggestion === null
            ? self::createForReason(ConfigurationError::EnvironmentVariableUnknown, $context)
            : self::createForReasonWithAppendedText(ConfigurationError::EnvironmentVariableUnknown, $context, \sprintf('Did you mean %s?', $suggestion));
    }

    public static function environmentVariableTooDeep(string $variable, string $valuePath): self
    {
        return self::createForReason(ConfigurationError::EnvironmentVariableTooDeep, ['variable' => $variable, 'valuePath' => $valuePath]);
    }

    public static function environmentVariableNamesSection(string $variable, string $separator): self
    {
        return self::createForReason(ConfigurationError::EnvironmentVariableNamesSection, ['variable' => $variable, 'separator' => $separator]);
    }

    public static function environmentValueInvalid(string $variable, string $expected, string $raw): self
    {
        return self::createForReason(ConfigurationError::EnvironmentValueInvalid, ['variable' => $variable, 'expected' => $expected, 'raw' => $raw]);
    }

    public static function environmentValueUnparsable(string $variable, Throwable $previous): self
    {
        return self::createForReason(ConfigurationError::EnvironmentValueUnparsable, ['variable' => $variable], $previous);
    }

    public static function environmentVariableConflict(string $variable, string $otherVariable, string $valuePath): self
    {
        return self::createForReason(ConfigurationError::EnvironmentVariableConflict, ['variable' => $variable, 'otherVariable' => $otherVariable, 'valuePath' => $valuePath]);
    }

    public static function environmentSecretFileUnreadable(string $variable, string $path): self
    {
        return self::createForReason(ConfigurationError::EnvironmentSecretFileUnreadable, ['variable' => $variable, 'path' => $path]);
    }

    private static function describeKnownApps(AppIdCollection $knownAppIds): string
    {
        return $knownAppIds->isEmpty()
            ? 'No automations were found; check the #[Automation] attribute.'
            : 'Known automations: ' . implode(', ', $knownAppIds->toStrings()) . '.';
    }
}
