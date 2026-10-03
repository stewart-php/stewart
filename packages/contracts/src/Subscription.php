<?php

declare(strict_types=1);

namespace Stewart\Contracts;

interface Subscription
{
    public function unsubscribe(): void;

    public function isActive(): bool;

    public function getId(): string;
}
