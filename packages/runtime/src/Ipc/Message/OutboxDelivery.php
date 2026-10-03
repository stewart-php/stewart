<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Message;

enum OutboxDelivery
{
    case Plain;
    case Droppable;
    case LatestOnly;
    case StateSegmentBoundary;
}
