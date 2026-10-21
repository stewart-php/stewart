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

    private function __construct(
        public string $label,
        public ?ResourceScope $scope,
    ) {}

    public static function broker(): self
    {
        return new self('broker', null);
    }

    public static function forScope(ResourceScope $scope): self
    {
        return new self($scope->wireValue(), $scope);
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
}
