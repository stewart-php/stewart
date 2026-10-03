<?php

declare(strict_types=1);

namespace Stewart\Client\State;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stewart\Contracts\Entity\EntityId;
use Stewart\Contracts\State\EntityState;
use Stewart\Contracts\State\EventContext;
use Stewart\Contracts\Time\Instant;
use Stewart\Support\Json\JsonShape;

final class EntityStateDecoder
{
    /** @var array<string, true> */
    private array $warnedFields = [];

    public function __construct(private readonly LoggerInterface $logger = new NullLogger()) {}

    /** @param array<array-key, mixed> $raw */
    public function decodeEntityStateOrSkip(array $raw): ?EntityState
    {
        $entityId = $this->parseEntityIdOrWarn($raw['entity_id'] ?? null);

        if ($entityId === null) {
            return null;
        }

        $attributes = $raw['attributes'] ?? [];
        $context = $raw['context'] ?? null;
        $state = $raw['state'] ?? '';

        return new EntityState(
            entityId: $entityId,
            state: \is_scalar($state) ? (string) $state : '',
            attributes: \is_array($attributes) ? JsonShape::treatKeysAsStrings($attributes) : [],
            lastChangedAt: $this->parseInstantOrWarn($raw['last_changed'] ?? null, $entityId->value, 'last_changed'),
            lastUpdatedAt: $this->parseInstantOrWarn($raw['last_updated'] ?? null, $entityId->value, 'last_updated'),
            context: \is_array($context) ? EventContext::fromArray(JsonShape::treatKeysAsStrings($context)) : null,
        );
    }

    public function parseEntityIdOrWarn(mixed $raw): ?EntityId
    {
        $entityId = \is_string($raw) ? EntityId::tryFromString($raw) : null;

        if ($entityId === null) {
            $this->warnOncePerField(\is_string($raw) ? $raw : '', 'entity_id', $raw, 'Home Assistant sent an entity without a valid entity id; it is skipped');
        }

        return $entityId;
    }

    public function parseInstantOrWarn(mixed $raw, string $source, string $field): ?Instant
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $instant = \is_string($raw) ? Instant::tryFromIso($raw) : null;

        if ($instant === null) {
            $this->warnOncePerField($source, $field, $raw, 'Home Assistant sent a timestamp that is not ISO-8601; it is treated as missing');
        }

        return $instant;
    }

    private function warnOncePerField(string $source, string $field, mixed $raw, string $message): void
    {
        // A broken integration sends the same bad value on every change, so each field warns once.
        $key = $source . "\0" . $field;

        if (isset($this->warnedFields[$key])) {
            return;
        }

        $this->warnedFields[$key] = true;
        $this->logger->warning($message, [
            'source' => $source,
            'field' => $field,
            'value' => \is_scalar($raw) ? (string) $raw : get_debug_type($raw),
        ]);
    }
}
