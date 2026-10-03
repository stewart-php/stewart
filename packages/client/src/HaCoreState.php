<?php

declare(strict_types=1);

namespace Stewart\Client;

enum HaCoreState: string
{
    case NotRunning = 'NOT_RUNNING';
    case Starting = 'STARTING';
    case Running = 'RUNNING';
    case Stopping = 'STOPPING';
    case FinalWrite = 'FINAL_WRITE';
    case Stopped = 'STOPPED';
}
