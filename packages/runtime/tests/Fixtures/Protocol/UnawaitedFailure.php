<?php

declare(strict_types=1);

namespace Stewart\Runtime\Tests\Fixtures\Protocol;

use RuntimeException;
use Stewart\Contracts\App;
use Stewart\Contracts\Automation;

use function Amp\async;

#[Automation(id: 'unawaited-failure')]
final readonly class UnawaitedFailure implements App
{
    public const string FAILURE = 'nobody awaited this';

    public function initialize(): void
    {
        async(static function (): void {
            throw new RuntimeException(self::FAILURE);
        });
    }

    public function dispose(): void {}
}
