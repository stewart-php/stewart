<?php

declare(strict_types=1);

namespace Stewart\Client\Connection;

/** @internal */
enum EnqueueOutcome
{
    case Accepted;
    case Full;
    case Closed;
}
