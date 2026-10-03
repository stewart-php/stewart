<?php

declare(strict_types=1);

namespace Stewart\Store\Tests\Fixtures;

use LogicException;
use Stewart\Store\StoreBackend;
use Stewart\Store\StoreBackendFactory;
use Stewart\Store\StoreDsn;
use Stewart\Store\StoreTiming;

final readonly class SchemeOnlyBackendFactory implements StoreBackendFactory
{
    /** @var list<string> */
    private array $schemes;

    public function __construct(string ...$schemes)
    {
        $this->schemes = array_values($schemes);
    }

    public function listSchemes(): array
    {
        return $this->schemes;
    }

    public function createBackend(StoreDsn $dsn, StoreTiming $timing): StoreBackend
    {
        throw new LogicException('Registry tests never open a backend.');
    }
}
