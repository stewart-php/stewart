<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Psr\Log\LoggerInterface;
use Stewart\Runtime\Exception\TransportError;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\BrokerMessage;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Worker\Message\BrokerMessageDispatcher;
use Throwable;

final readonly class BrokerMessageReader
{
    public function __construct(
        private Transport $transport,
        private BrokerMessageDispatcher $messages,
        private WorkerShutdown $shutdown,
        private LoggerInterface $logger,
    ) {}

    public function readUntilChannelCloses(): void
    {
        while (true) {
            try {
                $message = $this->transport->receive();
            } catch (TransportException $e) {
                if ($e->reason === TransportError::UndecodableFrame) {
                    $this->logger->warning('Dropped a broker message the worker could not decode', [
                        'exception' => $e,
                    ]);

                    continue;
                }

                $this->shutdown->stopAfterBrokerLoss('broker gone: ' . $e->getMessage());

                return;
            } catch (Throwable $e) {
                $this->logger->error('Worker stopped reading broker messages', ['exception' => $e]);
                $this->shutdown->stopAfterBrokerLoss('reading from the broker failed: ' . $e->getMessage());

                return;
            }

            if ($message === null) {
                $this->shutdown->stopAfterBrokerLoss('channel closed');

                return;
            }

            if (!$message instanceof BrokerMessage) {
                $this->logger->warning('Dropped a message that is not a broker message', ['message' => $message::class]);

                continue;
            }

            try {
                $this->messages->dispatch($message);
            } catch (Throwable $e) {
                $this->logger->error('Worker failed handling a broker message', [
                    'message' => $message::class,
                    'exception' => $e,
                ]);
            }
        }
    }
}
