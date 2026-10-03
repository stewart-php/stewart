<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Message;

use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\MessageHandler;

/**
 * @template T of BrokerMessage
 *
 * @extends MessageHandler<T>
 */
interface BrokerMessageHandler extends MessageHandler
{
    /** @param T $message */
    public function handle(BrokerMessage $message): void;
}
