<?php

declare(strict_types=1);

namespace Stewart\Runtime\Control\Protocol\Frame;

final readonly class ResumeAppRequest implements ClientFrame
{
    public function __construct(public string $appId) {}
}
