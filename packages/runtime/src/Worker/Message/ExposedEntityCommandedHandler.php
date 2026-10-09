<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Message\ExposedEntityCommanded;
use Stewart\Runtime\Worker\Exposure\ExposedCommandSettlements;
use Stewart\Runtime\Worker\Exposure\ExposedCommandStreams;
use Stewart\Runtime\Worker\Exposure\ExposedHandleRegistry;

/** @implements BrokerMessageHandler<ExposedEntityCommanded> */
final readonly class ExposedEntityCommandedHandler implements BrokerMessageHandler
{
    public function __construct(
        private ExposedHandleRegistry $handles,
        private ExposedCommandStreams $commandStreams,
        private ExposedCommandSettlements $settlements,
    ) {}

    public function handledMessageClass(): string
    {
        return ExposedEntityCommanded::class;
    }

    /** @param ExposedEntityCommanded $message */
    public function handle(BrokerMessage $message): void
    {
        $handle = $this->handles->findHandle($message->scope, $message->key);

        if ($handle === null) {
            $this->settlements->refuseUnknownCommand($message->commandId, \sprintf('Entity %s is no longer exposed.', $message->key));

            return;
        }

        $this->commandStreams->deliverCommand($message->commandId, $message->scope, $message->key, $message->command, $handle);
    }
}
