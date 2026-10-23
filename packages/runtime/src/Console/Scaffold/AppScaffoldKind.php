<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console\Scaffold;

enum AppScaffoldKind
{
    case Automation;
    case Template;

    public function getAppStub(): string
    {
        return match ($this) {
            self::Automation => 'App.php.stub',
            self::Template => 'AppTemplate.php.stub',
        };
    }

    public function getTestStub(): string
    {
        return match ($this) {
            self::Automation => 'AppTest.php.stub',
            self::Template => 'AppTemplateTest.php.stub',
        };
    }
}
