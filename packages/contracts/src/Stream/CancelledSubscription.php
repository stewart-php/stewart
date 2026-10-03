<?php

declare(strict_types=1);

namespace Stewart\Contracts\Stream;

use Stewart\Contracts\Subscription;

/** @internal */
final readonly class CancelledSubscription implements Subscription
{
    private function __construct(private string $id) {}

    public static function createUnique(): self
    {
        return new self('cancelled:' . bin2hex(random_bytes(8)));
    }

    public function unsubscribe(): void {}

    public function isActive(): bool
    {
        return false;
    }

    public function getId(): string
    {
        return $this->id;
    }
}
