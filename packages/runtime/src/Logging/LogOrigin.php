<?php

declare(strict_types=1);

namespace Stewart\Runtime\Logging;

use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Model\ResourceScope;
use Stewart\Runtime\Model\WorkerId;

final readonly class LogOrigin
{
    public const string CONTEXT_KEY = 'stewartLogOrigin';

    public const string SOURCE_KEY = 'source';

    public const string TRACE_KEY = 'stewartTrace';

    private const string TRACE_LEVEL_NAME = 'TRACE';

    private function __construct(
        public string $label,
        public ?ResourceScope $scope,
        public bool $traced = false,
    ) {}

    public static function broker(): self
    {
        return new self('broker', null);
    }

    public static function forScope(ResourceScope $scope, bool $traced = false): self
    {
        return new self($scope->wireValue(), $scope, $traced);
    }

    public static function forApp(AppId $appId): self
    {
        return self::forScope(ResourceScope::forApp($appId));
    }

    public static function forWorkerOutput(WorkerId $workerId): self
    {
        return new self('worker ' . $workerId->value, null);
    }

    /** @param array<mixed> $context */
    public static function findInContext(array $context): ?self
    {
        $origin = $context[self::CONTEXT_KEY] ?? null;

        return $origin instanceof self ? $origin : null;
    }

    // A trace record stays a debug record for Monolog; only its printed level name differs.
    /**
     * @template TValue
     * @param array<TValue> $normalized
     * @return array<TValue|string|array<mixed>>
     */
    public static function relabelTracedRecord(array $normalized): array
    {
        $extra = $normalized['extra'] ?? null;

        if (!\is_array($extra) || !\array_key_exists(self::TRACE_KEY, $extra)) {
            return $normalized;
        }

        unset($extra[self::TRACE_KEY]);
        $normalized['extra'] = $extra;
        $normalized['level_name'] = self::TRACE_LEVEL_NAME;

        return $normalized;
    }
}
