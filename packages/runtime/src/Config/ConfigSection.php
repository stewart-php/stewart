<?php

declare(strict_types=1);

namespace Stewart\Runtime\Config;

use Closure;
use SensitiveParameter;
use Stewart\Contracts\Exception\StewartException;
use Stewart\Contracts\Time\Duration;
use Stewart\Runtime\Exception\ConfigurationError;
use Stewart\Runtime\Exception\ConfigurationException;

final readonly class ConfigSection
{
    /** @param array<array-key, mixed> $values */
    private function __construct(
        #[SensitiveParameter]
        private array $values,
        private string $path,
    ) {}

    /** @param array<array-key, mixed> $values */
    public static function forRoot(#[SensitiveParameter] array $values): self
    {
        return new self($values, '');
    }

    /** @throws ConfigurationException */
    public function readSection(string $key): self
    {
        return $this->findSection($key) ?? throw $this->createKeyInvalidFailure($key, 'a section');
    }

    public function findSection(string $key): ?self
    {
        $value = $this->values[$key] ?? null;

        return \is_array($value) ? new self($value, $this->buildKeyPath($key)) : null;
    }

    /**
     * @template T
     * @param Closure(self, string): T $read
     * @return list<T>
     */
    public function mapSubsections(Closure $read): array
    {
        $mapped = [];

        foreach ($this->values as $key => $value) {
            if (\is_array($value)) {
                $mapped[] = $read(new self($value, $this->buildKeyPath((string) $key)), (string) $key);
            }
        }

        return $mapped;
    }

    /** @throws ConfigurationException */
    public function readInt(string $key): int
    {
        $value = $this->values[$key] ?? null;

        return \is_int($value) ? $value : throw $this->createKeyInvalidFailure($key, 'a whole number');
    }

    /** @throws ConfigurationException */
    public function readBool(string $key): bool
    {
        $value = $this->values[$key] ?? null;

        return \is_bool($value) ? $value : throw $this->createKeyInvalidFailure($key, 'true or false');
    }

    /** @throws ConfigurationException */
    public function readString(string $key): string
    {
        return $this->findString($key) ?? throw $this->createKeyInvalidFailure($key, 'a string');
    }

    public function findString(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        return \is_scalar($value) && !\is_bool($value) ? (string) $value : null;
    }

    public function findInt(string $key): ?int
    {
        $value = $this->values[$key] ?? null;

        return \is_int($value) ? $value : null;
    }

    /**
     * @return list<string>
     * @throws ConfigurationException
     */
    public function readStringList(string $key): array
    {
        return array_values($this->readStringMap($key));
    }

    /**
     * @return array<string, string>
     * @throws ConfigurationException
     */
    public function readStringMap(string $key): array
    {
        $strings = [];

        foreach ($this->readMap($key) as $name => $value) {
            $strings[$name] = \is_string($value) ? $value : throw $this->createKeyInvalidFailure($key . '.' . $name, 'a string');
        }

        return $strings;
    }

    /**
     * @return array<string, mixed>
     * @throws ConfigurationException
     */
    public function readMap(string $key): array
    {
        $value = $this->values[$key] ?? [];

        if (!\is_array($value)) {
            throw $this->createKeyInvalidFailure($key, 'a map');
        }

        $map = [];

        foreach ($value as $name => $item) {
            $map[(string) $name] = $item;
        }

        return $map;
    }

    /** @throws ConfigurationException */
    public function readDuration(string $key, ?Duration $atLeast = null, ?Duration $atMost = null): Duration
    {
        $duration = $this->readParsedValue($key, Duration::parse(...));

        if ($atLeast?->isLongerThan($duration) === true) {
            throw ConfigurationException::createForReason(
                ConfigurationError::DurationTooShort,
                path: $this->buildKeyPath($key),
                value: (string) $duration,
                minimum: (string) $atLeast,
            );
        }

        if ($atMost !== null && $duration->isLongerThan($atMost)) {
            throw ConfigurationException::createForReason(
                ConfigurationError::DurationTooLong,
                path: $this->buildKeyPath($key),
                value: (string) $duration,
                maximum: (string) $atMost,
            );
        }

        return $duration;
    }

    /** @throws ConfigurationException */
    public function readOptionalDuration(string $key, ?Duration $atLeast = null): OptionalDuration
    {
        $optional = $this->readParsedValue($key, OptionalDuration::parse(...));
        $duration = $optional->findDuration();

        if ($duration !== null && $atLeast?->isLongerThan($duration) === true) {
            throw ConfigurationException::createForReason(
                ConfigurationError::DurationTooShort,
                path: $this->buildKeyPath($key),
                value: (string) $duration,
                minimum: (string) $atLeast,
            );
        }

        return $optional;
    }

    /** @throws ConfigurationException */
    public function readBackoff(string $keyPrefix): BackoffPolicy
    {
        $oneMillisecond = Duration::milliseconds(1);
        $initialDelay = $this->readDuration($keyPrefix . 'initial_delay', $oneMillisecond);
        $maxDelay = $this->readDuration($keyPrefix . 'max_delay', $oneMillisecond);

        if ($initialDelay->isLongerThan($maxDelay)) {
            throw ConfigurationException::createForReason(
                ConfigurationError::MaxDelayBelowInitialDelay,
                path: $this->buildKeyPath($keyPrefix . 'max_delay'),
                value: (string) $maxDelay,
                initialDelayPath: $this->buildKeyPath($keyPrefix . 'initial_delay'),
                initialDelay: (string) $initialDelay,
            );
        }

        return new BackoffPolicy($initialDelay, $maxDelay);
    }

    /**
     * @template T
     * @param Closure(string): T $parse
     * @return T
     * @throws ConfigurationException
     */
    public function readParsedValue(string $key, Closure $parse): mixed
    {
        $raw = $this->readString($key);

        try {
            return $parse($raw);
        } catch (StewartException $e) {
            throw ConfigurationException::createForReason(ConfigurationError::KeyParseFailed, $e, path: $this->buildKeyPath($key));
        }
    }

    private function createKeyInvalidFailure(string $key, string $expected): ConfigurationException
    {
        return ConfigurationException::createForReason(ConfigurationError::KeyInvalid, path: $this->buildKeyPath($key), expected: $expected);
    }

    private function buildKeyPath(string $key): string
    {
        return $this->path === '' ? $key : $this->path . '.' . $key;
    }
}
