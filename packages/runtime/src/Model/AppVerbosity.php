<?php

declare(strict_types=1);

namespace Stewart\Runtime\Model;

final readonly class AppVerbosity
{
    public function __construct(public ?LogLevel $logLevel = null) {}
}
