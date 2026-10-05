<?php

declare(strict_types=1);

namespace Stewart\Contracts\Trigger;

use Stewart\Contracts\Exception\TriggerException;
use Stewart\Contracts\Trigger\Collection\HaTriggerCollection;

final readonly class TriggerSpec
{
    /** @param array<string, mixed> $variables */
    private function __construct(
        public HaTriggerCollection $triggers,
        public array $variables,
    ) {}

    /**
     * @param HaTrigger|HaTriggerCollection|array<array-key, mixed> $trigger
     * @param array<array-key, mixed> $variables
     * @throws TriggerException
     */
    public static function fromSpec(HaTrigger|HaTriggerCollection|array $trigger, array $variables = []): self
    {
        $triggers = match (true) {
            $trigger instanceof HaTrigger => HaTriggerCollection::fromTriggers([$trigger]),
            $trigger instanceof HaTriggerCollection => $trigger,
            default => self::parseTriggers($trigger),
        };

        if ($triggers->isEmpty()) {
            throw TriggerException::listEmpty();
        }

        return new self($triggers, self::parseVariables($variables));
    }

    public function getSharingKey(): string
    {
        return hash('xxh128', TriggerJson::encodeCanonically([$this->listTriggerConfigs(), $this->variables]));
    }

    public function hasSameTriggersAs(self $other): bool
    {
        return TriggerJson::encodeCanonically($this->listTriggerConfigs()) === TriggerJson::encodeCanonically($other->listTriggerConfigs());
    }

    /** @return list<string> */
    public function listPlatforms(): array
    {
        return $this->triggers->mapToList(static fn(HaTrigger $trigger): string => $trigger->getPlatform());
    }

    /** @return list<array<string, mixed>> */
    public function listTriggerConfigs(): array
    {
        return $this->triggers->mapToList(static fn(HaTrigger $trigger): array => $trigger->toArray());
    }

    /**
     * @param array<array-key, mixed> $trigger
     * @throws TriggerException
     */
    private static function parseTriggers(array $trigger): HaTriggerCollection
    {
        if (!array_is_list($trigger)) {
            return HaTriggerCollection::fromTriggers([HaTrigger::fromArray($trigger)]);
        }

        $triggers = [];

        foreach ($trigger as $config) {
            if (!\is_array($config)) {
                throw TriggerException::configInvalid();
            }

            $triggers[] = HaTrigger::fromArray($config);
        }

        return HaTriggerCollection::fromTriggers($triggers);
    }

    /**
     * @param array<array-key, mixed> $variables
     * @return array<string, mixed>
     * @throws TriggerException
     */
    private static function parseVariables(array $variables): array
    {
        $typedVariables = [];

        foreach ($variables as $name => $value) {
            if (!\is_string($name)) {
                throw TriggerException::variablesNotMap();
            }

            $typedVariables[$name] = $value;
        }

        $unencodablePath = TriggerJson::findUnencodablePath($typedVariables, 'variables');

        if ($unencodablePath !== null) {
            throw TriggerException::configUnencodable($unencodablePath);
        }

        return $typedVariables;
    }
}
