<?php

declare(strict_types=1);

namespace Stewart\Codegen\Snapshot;

use Closure;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stewart\Client\Registry\EntityRegistryEntry;
use Stewart\Client\State\EntityStateDecoder;
use Stewart\Codegen\Exception\CodegenException;
use Stewart\Contracts\Exception\IdentifierException;
use Stewart\Contracts\State\EntityState;
use Stewart\Support\Json\JsonDecoder;

final readonly class SnapshotCodec
{
    public function __construct(private EntityStateDecoder $entityStateDecoder) {}

    public function encodeSnapshot(Snapshot $snapshot): string
    {
        return json_encode(
            $this->buildSnapshotArray($snapshot),
            // Keeps 1.0 a float; as 1 it would decode as int and change the inferred type.
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
                | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /** @return array<string, mixed> */
    private function buildSnapshotArray(Snapshot $snapshot): array
    {
        return [
            'ha_version' => $snapshot->haVersion,
            'states' => $snapshot->states->mapToList($this->buildEntityStateArray(...)),
            'registry' => $snapshot->registry->mapToList(static fn(EntityRegistryEntry $entry): array => $entry->toArray()),
            'services' => $snapshot->services,
        ];
    }

    /** @return array<string, mixed> */
    private function buildEntityStateArray(EntityState $state): array
    {
        $context = $state->context;

        return [
            'entity_id' => $state->entityId->value,
            'state' => $state->state,
            'attributes' => $state->attributes,
            'last_changed' => $state->lastChangedAt?->toIso8601(),
            'last_updated' => $state->lastUpdatedAt?->toIso8601(),
            'context' => $context === null ? null : ['id' => $context->id, 'parent_id' => $context->parentId, 'user_id' => $context->userId],
        ];
    }

    /** @throws CodegenException */
    public function decodeSnapshot(string $json, string $origin, LoggerInterface $logger = new NullLogger()): Snapshot
    {
        try {
            $decoded = JsonDecoder::decodeJson($json);
        } catch (JsonException $e) {
            throw CodegenException::snapshotNotJson($origin, $e->getMessage());
        }

        if (!\is_array($decoded) || !\array_key_exists('states', $decoded)) {
            throw CodegenException::notASnapshot($origin);
        }

        return $this->buildSnapshotFromDecodedJson($decoded, $logger);
    }

    /** @param array<array-key, mixed> $decoded */
    private function buildSnapshotFromDecodedJson(array $decoded, LoggerInterface $logger): Snapshot
    {
        $services = [];

        foreach (\is_array($decoded['services'] ?? null) ? $decoded['services'] : [] as $domain => $definitions) {
            $services[(string) $domain] = $definitions;
        }

        return Snapshot::fromParts(
            haVersion: \is_string($decoded['ha_version'] ?? null) ? $decoded['ha_version'] : 'unknown',
            states: $this->decodeEntriesSkippingInvalidIds($decoded['states'] ?? null, $this->entityStateDecoder->decodeEntityStateOrSkip(...), $logger),
            registry: $this->decodeEntriesSkippingInvalidIds($decoded['registry'] ?? null, EntityRegistryEntry::fromArray(...), $logger),
            services: $services,
        );
    }

    /**
     * @template T of object
     * @param Closure(array<array-key, mixed>): ?T $decodeEntry
     * @return list<T>
     */
    private function decodeEntriesSkippingInvalidIds(mixed $entries, Closure $decodeEntry, LoggerInterface $logger): array
    {
        $decoded = [];

        foreach (\is_array($entries) ? $entries : [] as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            try {
                $decodedEntry = $decodeEntry($entry);
            } catch (IdentifierException $e) {
                $decodedEntry = null;
            }

            if ($decodedEntry === null) {
                $logger->warning('Skipped a snapshot entry without a valid entity id', ['entry' => $entry['entity_id'] ?? null]);

                continue;
            }

            $decoded[] = $decodedEntry;
        }

        return $decoded;
    }
}
