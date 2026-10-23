<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console\Scaffold;

use Stewart\Contracts\App\AppId;

final readonly class ScaffoldedApp
{
    public function __construct(
        public AppId $id,
        public string $className,
        public AppScaffoldKind $kind,
        public string $appFile,
        public string $testFile,
    ) {}
}
