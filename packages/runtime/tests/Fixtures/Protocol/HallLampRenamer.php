<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use Stewart\Contracts\App;
use Stewart\Contracts\Automation;
use Stewart\Contracts\Registry\RegistryEditor;
use Stewart\Contracts\Registry\Update\EntityRegistryUpdate;

#[Automation(id: 'hall-lamp-renamer')]
final readonly class HallLampRenamer implements App
{
    public const string ENTITY_ID = 'light.hall';

    public const string NAME = 'Hall lamp';

    public function __construct(private RegistryEditor $registry) {}

    public function initialize(): void
    {
        $this->registry->updateEntity(self::ENTITY_ID, new EntityRegistryUpdate()->withName(self::NAME)->withAddedLabels('night'));
    }

    public function dispose(): void {}
}
