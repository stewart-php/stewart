<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\StateChangeOrigin;
use Stewart\Runtime\Json\EpochInstantConverter;
use Stewart\Runtime\Json\StringIdentifierConverter;
use Stewart\Runtime\Json\ValueConverter;
use Stewart\Support\Exception\JsonShapeException;
use Stewart\Support\State\StateUpdate;

final readonly class StateUpdateConverter implements ValueConverter
{
    /** @param StringIdentifierConverter<EntityId> $entityIds */
    public function __construct(
        private StringIdentifierConverter $entityIds,
        private EpochInstantConverter $instants,
        private EventContextConverter $contexts,
        private EntityStateConverter $states,
    ) {}

    public function handledClass(): string
    {
        return StateUpdate::class;
    }

    public function keySuffix(): string
    {
        return '';
    }

    /** @return array<string, mixed> */
    public function encodeValue(object $value): array
    {
        \assert($value instanceof StateUpdate);

        return [
            'entity_id' => $this->entityIds->encodeValue($value->entityId),
            'to' => $value->to === null ? null : $this->states->encodeValue($value->to),
            'fired_at_us' => $value->firedAt === null ? null : $this->instants->encodeValue($value->firedAt),
            'context' => $value->context === null ? null : $this->contexts->encodeValue($value->context),
            'origin' => $value->origin->value,
        ];
    }

    /** @throws JsonShapeException */
    public function decodeValue(mixed $value, string $path): StateUpdate
    {
        if (!\is_array($value)) {
            throw JsonShapeException::wrongType($path, 'an object', get_debug_type($value));
        }

        $to = $value['to'] ?? null;
        $firedAt = $value['fired_at_us'] ?? null;
        $context = $value['context'] ?? null;

        return new StateUpdate(
            $this->entityIds->decodeValue($value['entity_id'] ?? null, $path . '.entity_id'),
            $to === null ? null : $this->states->decodeValue($to, $path . '.to'),
            $firedAt === null ? null : $this->instants->decodeValue($firedAt, $path . '.fired_at_us'),
            $context === null ? null : $this->contexts->decodeValue($context, $path . '.context'),
            $this->decodeOrigin($value['origin'] ?? null, $path),
        );
    }

    /** @throws JsonShapeException */
    private function decodeOrigin(mixed $origin, string $path): StateChangeOrigin
    {
        $decoded = \is_string($origin) ? StateChangeOrigin::tryFrom($origin) : null;

        return $decoded ?? throw JsonShapeException::unexpectedValue(
            $path . '.origin',
            'one of ' . implode(', ', array_map(static fn(StateChangeOrigin $case): string => $case->value, StateChangeOrigin::cases())),
            $origin,
        );
    }
}
