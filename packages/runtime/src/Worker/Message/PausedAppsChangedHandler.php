<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\PausedAppsChanged;
use Stewart\Runtime\Worker\PausedAppsSync;

/** @implements BrokerMessageHandler<PausedAppsChanged> */
final readonly class PausedAppsChangedHandler implements BrokerMessageHandler
{
    public function __construct(private PausedAppsSync $pausedApps) {}

    public function handledMessageClass(): string
    {
        return PausedAppsChanged::class;
    }

    /** @param PausedAppsChanged $message */
    public function handle(BrokerMessage $message): void
    {
        $this->pausedApps->applyPausedAppIds($message->pausedAppIds->collection);
    }
}
