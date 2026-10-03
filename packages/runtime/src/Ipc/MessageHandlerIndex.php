<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc;

use Stewart\Runtime\Exception\ContainerException;

/** @template THandler of MessageHandler */
final readonly class MessageHandlerIndex
{
    /** @param array<class-string, THandler> $handlersByMessageClass */
    private function __construct(private array $handlersByMessageClass) {}

    /**
     * @template TIndexed of MessageHandler
     *
     * @param iterable<TIndexed> $handlers
     *
     * @return self<TIndexed>
     *
     * @throws ContainerException
     */
    public static function fromHandlers(iterable $handlers): self
    {
        $handlersByMessageClass = [];

        foreach ($handlers as $handler) {
            $messageClass = $handler->handledMessageClass();
            $registered = $handlersByMessageClass[$messageClass] ?? null;

            if ($registered !== null) {
                throw ContainerException::messageHandlerDuplicated($messageClass, $registered::class, $handler::class);
            }

            $handlersByMessageClass[$messageClass] = $handler;
        }

        return new self($handlersByMessageClass);
    }

    /** @return THandler|null */
    public function findHandlerFor(object $message): ?MessageHandler
    {
        return $this->handlersByMessageClass[$message::class] ?? null;
    }
}
