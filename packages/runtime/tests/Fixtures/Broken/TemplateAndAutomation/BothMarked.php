<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Broken\TemplateAndAutomation;

use Stewart\Contracts\App;
use Stewart\Contracts\AppTemplate;
use Stewart\Contracts\Automation;

#[AppTemplate]
#[Automation(id: 'both-marked')]
final class BothMarked implements App
{
    public function initialize(): void {}
}
