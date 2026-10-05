<?php

declare(strict_types=1);

namespace Stewart\Contracts\Trigger;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Contracts\Exception\TriggerException;
use Stewart\Contracts\Time\Duration;
use Stewart\Contracts\Time\SunOffset;

final readonly class HaTrigger
{
    private const string TIME_OF_DAY_PATTERN = '/\A([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?\z/';

    private const array TIME_ENTITY_DOMAINS = ['input_datetime', 'sensor'];

    /** @param array<string, mixed> $config */
    private function __construct(private array $config) {}

    /**
     * @param array<array-key, mixed> $config
     * @throws TriggerException
     */
    public static function fromArray(array $config): self
    {
        $platform = $config['trigger'] ?? $config['platform'] ?? null;

        if (!\is_string($platform) || $platform === '') {
            throw TriggerException::configInvalid();
        }

        $typedConfig = [];

        foreach ($config as $key => $value) {
            if (!\is_string($key)) {
                throw TriggerException::configInvalid();
            }

            $typedConfig[$key] = $value;
        }

        $unencodablePath = TriggerJson::findUnencodablePath($typedConfig, 'trigger');

        if ($unencodablePath !== null) {
            throw TriggerException::configUnencodable($unencodablePath);
        }

        return new self($typedConfig);
    }

    public static function onSunrise(?SunOffset $offset = null, ?string $id = null): self
    {
        return self::createSunTrigger('sunrise', $offset, $id);
    }

    public static function onSunset(?SunOffset $offset = null, ?string $id = null): self
    {
        return self::createSunTrigger('sunset', $offset, $id);
    }

    /** @throws TriggerException */
    public static function atTime(string|EntityId $time, ?string $id = null): self
    {
        if (\is_string($time) && preg_match(self::TIME_OF_DAY_PATTERN, $time) === 1) {
            return self::createWithId(['trigger' => 'time', 'at' => $time], $id);
        }

        $entityId = \is_string($time) ? EntityId::tryFromString($time) : $time;

        if ($entityId === null || !\in_array($entityId->domain, self::TIME_ENTITY_DOMAINS, true)) {
            throw TriggerException::timeInvalid((string) $time);
        }

        return self::createWithId(['trigger' => 'time', 'at' => $entityId->value], $id);
    }

    /** @throws TriggerException */
    public static function onTimePattern(
        int|string|null $hours = null,
        int|string|null $minutes = null,
        int|string|null $seconds = null,
        ?string $id = null,
    ): self {
        $pattern = array_filter(
            ['hours' => $hours, 'minutes' => $minutes, 'seconds' => $seconds],
            static fn(int|string|null $part): bool => $part !== null,
        );

        if ($pattern === []) {
            throw TriggerException::timePatternEmpty();
        }

        return self::createWithId(['trigger' => 'time_pattern', ...$pattern], $id);
    }

    /** @throws TriggerException */
    public static function whenTemplateTrue(string $template, ?Duration $for = null, ?string $id = null): self
    {
        if (trim($template) === '') {
            throw TriggerException::templateEmpty();
        }

        $config = ['trigger' => 'template', 'value_template' => $template];

        if ($for !== null) {
            $config['for'] = $for->formatAsClock();
        }

        return self::createWithId($config, $id);
    }

    /** @throws TriggerException|IdentifierException */
    public static function onZoneTransition(
        EntityId|string $entity,
        EntityId|string $zone,
        ZoneTransition $transition,
        ?string $id = null,
    ): self {
        $zoneId = EntityId::fromStringOrId($zone);

        if ($zoneId->domain !== 'zone') {
            throw TriggerException::zoneInvalid($zoneId->value);
        }

        return self::createWithId([
            'trigger' => 'zone',
            'entity_id' => EntityId::fromStringOrId($entity)->value,
            'zone' => $zoneId->value,
            'event' => $transition->value,
        ], $id);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->config;
    }

    public function getPlatform(): string
    {
        $platform = $this->config['trigger'] ?? $this->config['platform'];
        \assert(\is_string($platform));

        return $platform;
    }

    private static function createSunTrigger(string $event, ?SunOffset $offset, ?string $id): self
    {
        $config = ['trigger' => 'sun', 'event' => $event];

        if ($offset !== null && !$offset->isNone()) {
            $config['offset'] = $offset->formatAsHaOffset();
        }

        return self::createWithId($config, $id);
    }

    /** @param array<string, mixed> $config */
    private static function createWithId(array $config, ?string $id): self
    {
        return new self($id === null ? $config : [...$config, 'id' => $id]);
    }
}
