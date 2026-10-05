<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command;

use Stewart\Contracts\Trigger\Collection\HaTriggerCollection;
use Stewart\Contracts\Trigger\HaTrigger;

final readonly class SubscribeTrigger implements SubscriptionCommand
{
    /** @param array<string, mixed> $variables */
    public function __construct(
        public HaTriggerCollection $triggers,
        public array $variables = [],
    ) {}

    public function type(): string
    {
        return 'subscribe_trigger';
    }

    public function describe(): string
    {
        $platforms = $this->triggers->mapToList(static fn(HaTrigger $trigger): string => $trigger->getPlatform());

        return \sprintf('subscribe_trigger "%s"', implode(',', $platforms));
    }

    public function toMessage(): array
    {
        $message = [
            'type' => $this->type(),
            'trigger' => $this->triggers->mapToList(static fn(HaTrigger $trigger): array => $trigger->toArray()),
        ];

        if ($this->variables !== []) {
            $message['variables'] = $this->variables;
        }

        return $message;
    }
}
