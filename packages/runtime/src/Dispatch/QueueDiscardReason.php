<?php

declare(strict_types=1);

namespace Stewart\Runtime\Dispatch;

enum QueueDiscardReason
{
    case Paused;
    case Overflow;
    case Cancelled;
}
