<?php

declare(strict_types=1);

namespace Stewart\Runtime\Model;

use Stewart\Contracts\App\AppId;
use Stringable;

final readonly class ResourceScope implements Stringable
{
    private const string SHARED = '@shared';

    private function __construct(public ?AppId $appId) {}

    public static function forApp(AppId $appId): self
    {
        return new self($appId);
    }

    public static function shared(): self
    {
        return new self(null);
    }

    public static function tryFromWireValue(string $value): ?self
    {
        if ($value === self::SHARED) {
            return self::shared();
        }

        $appId = AppId::tryFromString($value);

        return $appId === null ? null : self::forApp($appId);
    }

    public function isShared(): bool
    {
        return $this->appId === null;
    }

    public function equals(self $other): bool
    {
        return $this->appId?->value === $other->appId?->value;
    }

    public function wireValue(): string
    {
        return $this->appId === null ? self::SHARED : $this->appId->value;
    }

    public function __toString(): string
    {
        return $this->wireValue();
    }
}
