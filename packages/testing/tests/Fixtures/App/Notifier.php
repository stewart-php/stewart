<?php

declare(strict_types=1);

namespace Stewart\Testing\Tests\Fixtures\App;

interface Notifier
{
    public function notify(string $message): void;
}
