<?php

declare(strict_types=1);

namespace Stewart\Runtime\App;

use ReflectionMethod;
use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Disposable;

final readonly class DiscoveredApp
{
    /** @param class-string $class */
    public function __construct(
        public string $class,
        public AppId $id,
    ) {}

    public function declaresDisposeWithoutDisposable(): bool
    {
        return !is_a($this->class, Disposable::class, true)
            && method_exists($this->class, 'dispose')
            && new ReflectionMethod($this->class, 'dispose')->isPublic();
    }
}
