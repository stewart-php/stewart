<?php

declare(strict_types=1);

namespace Stewart\Client\Event;

use Stewart\Client\State\EntityStateDecoder;
use Stewart\Contracts\Event\EventOrigin;
use Stewart\Contracts\Event\HaEvent;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\State\StateChange;
use Stewart\Contracts\State\StateChangeOrigin;
use Stewart\Contracts\Trigger\TriggerEvent;
use Stewart\Support\Json\JsonShape;

final readonly class EventDecoder
{
    public function __construct(private EntityStateDecoder $states) {}

    /** @param array<string, mixed> $event */
    public function decodeStateChange(array $event): ?StateChange
    {
        $data = $event['data'] ?? null;

        if (!\is_array($data)) {
            return null;
        }

        $entityId = $this->states->parseEntityIdOrWarn($data['entity_id'] ?? null);

        if ($entityId === null) {
            return null;
        }

        $old = $data['old_state'] ?? null;
        $new = $data['new_state'] ?? null;
        $context = $event['context'] ?? null;

        return new StateChange(
            entityId: $entityId,
            from: \is_array($old) ? $this->states->decodeEntityStateOrSkip($old) : null,
            to: \is_array($new) ? $this->states->decodeEntityStateOrSkip($new) : null,
            firedAt: $this->states->parseInstantOrWarn($event['time_fired'] ?? null, $entityId->value, 'time_fired'),
            context: \is_array($context) ? EventContext::fromArray($context) : null,
            origin: StateChangeOrigin::Live,
        );
    }

    /** @param array<string, mixed> $event */
    public function decodeEvent(array $event): ?HaEvent
    {
        $type = $event['event_type'] ?? null;

        if (!\is_string($type) || $type === '') {
            return null;
        }

        $data = $event['data'] ?? null;
        $context = $event['context'] ?? null;
        $origin = $event['origin'] ?? null;

        return new HaEvent(
            type: $type,
            data: \is_array($data) ? JsonShape::treatKeysAsStrings($data) : [],
            origin: \is_string($origin) ? EventOrigin::tryFrom($origin) ?? EventOrigin::Local : EventOrigin::Local,
            firedAt: $this->states->parseInstantOrWarn($event['time_fired'] ?? null, $type, 'time_fired'),
            context: \is_array($context) ? EventContext::fromArray($context) : null,
        );
    }

    /** @param array<string, mixed> $event */
    public function decodeTriggerEvent(array $event): ?TriggerEvent
    {
        $variables = $event['variables'] ?? null;
        $trigger = \is_array($variables) ? $variables['trigger'] ?? null : null;

        if (!\is_array($trigger)) {
            return null;
        }

        $context = $event['context'] ?? null;

        return new TriggerEvent(
            trigger: JsonShape::treatKeysAsStrings($trigger),
            context: \is_array($context) ? EventContext::fromArray($context) : null,
        );
    }
}
