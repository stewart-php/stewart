<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\ExposedEntitySynced;
use Stewart\Runtime\Worker\Exposure\ExposedHandleRegistry;

/** @implements BrokerMessageHandler<ExposedEntitySynced> */
final readonly class ExposedEntitySyncedHandler implements BrokerMessageHandler
{
    public function __construct(private ExposedHandleRegistry $handles) {}

    public function handledMessageClass(): string
    {
        return ExposedEntitySynced::class;
    }

    /** @param ExposedEntitySynced $message */
    public function handle(BrokerMessage $message): void
    {
        $this->handles->applySnapshot($message->scope, $message->key, $message->snapshot);
    }
}
