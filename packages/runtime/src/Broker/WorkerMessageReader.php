<?php

declare(strict_types=1);

namespace Stewart\Runtime\Broker;

use Closure;
use Psr\Log\LoggerInterface;
use Stewart\Runtime\Exception\TransportError;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\WorkerMessage;
use Throwable;

use function Amp\async;

final readonly class WorkerMessageReader
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * @param Closure(WorkerMessage): void $onMessage
     * @param Closure(string): void $onGone
     */
    public function readMessagesInBackground(WorkerHandle $handle, Closure $onMessage, Closure $onGone): void
    {
        async(function () use ($handle, $onMessage, $onGone): void {
            try {
                while (($message = $this->receiveNextDecodableMessage($handle)) !== null) {
                    if ($message instanceof WorkerMessage) {
                        $this->deliverToHandler($handle, $message, $onMessage);
                    } else {
                        $this->logger->warning('Dropped a message that is not a worker message', [
                            'worker' => $handle->id->value,
                            'message' => $message::class,
                        ]);
                    }
                }

                $reason = 'closed the channel';
            } catch (Throwable $e) {
                $reason = $e->getMessage();
            }

            $this->reportGone($handle, $reason, $onGone);
        })->ignore();
    }

    /** @param Closure(string): void $onGone */
    private function reportGone(WorkerHandle $handle, string $reason, Closure $onGone): void
    {
        try {
            $onGone($reason);
        } catch (Throwable $e) {
            $this->logger->error('Failed handling a worker exit', [
                'worker' => $handle->id->value,
                'reason' => $reason,
                'exception' => $e,
            ]);
        }
    }

    /** @param Closure(WorkerMessage): void $onMessage */
    private function deliverToHandler(WorkerHandle $handle, WorkerMessage $message, Closure $onMessage): void
    {
        try {
            $onMessage($message);
        } catch (Throwable $e) {
            $this->logger->error('Failed handling a worker message', [
                'worker' => $handle->id->value,
                'message' => $message::class,
                'exception' => $e,
            ]);
        }
    }

    /** @throws TransportException */
    private function receiveNextDecodableMessage(WorkerHandle $handle): ?object
    {
        while (true) {
            try {
                return $handle->getTransport()->receive();
            } catch (TransportException $e) {
                if ($e->reason !== TransportError::UndecodableFrame) {
                    throw $e;
                }

                $this->logger->error('Dropped a worker message the broker could not decode', [
                    'worker' => $handle->id->value,
                    'exception' => $e,
                ]);
            }
        }
    }
}
