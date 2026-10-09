<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Exposure;

use Closure;
use Psr\Log\LoggerInterface;
use Stewart\Contracts\Exception\CommandException;
use Stewart\Contracts\Exposure\Command\ExposedCommand;
use Stewart\Runtime\Exception\TransportException;
use Stewart\Runtime\Ipc\Message\ExposedCommandAnswered;
use Stewart\Runtime\Ipc\Transport;
use Throwable;
use WeakMap;

// Weak keys let a command an operator dropped vanish; Home Assistant times it out.
final class ExposedCommandSettlements
{
    /** @var WeakMap<ExposedCommand, OpenExposedCommand> */
    private WeakMap $openCommands;

    public function __construct(
        private readonly Transport $transport,
        private readonly LoggerInterface $logger,
    ) {
        $this->openCommands = new WeakMap();
    }

    public function openCommand(string $commandId, ExposedCommand $command, ExposedHandle $handle): void
    {
        $this->openCommands[$command] = new OpenExposedCommand($commandId, $handle);
    }

    /**
     * @template T of ExposedCommand
     *
     * @param T $command
     * @param Closure(T): mixed $handler
     * @throws Throwable
     */
    public function settleThroughHandler(ExposedCommand $command, Closure $handler): void
    {
        try {
            $handler($command);
        } catch (CommandException $e) {
            $this->rejectCommand($command, $e->getMessage());

            return;
        } catch (Throwable $e) {
            $this->rejectCommand($command, 'The app failed to handle the command.');

            throw $e;
        }

        $this->acceptCommand($command);
    }

    public function acceptCommand(ExposedCommand $command): void
    {
        $open = $this->takeOpenCommand($command);

        if ($open === null) {
            return;
        }

        $requestedState = $command->getRequestedState();

        if ($requestedState !== null) {
            $open->handle->recordCommandedState($requestedState);
        }

        $this->sendAnswer(new ExposedCommandAnswered($open->commandId, true));
    }

    public function rejectCommand(ExposedCommand $command, string $reason): void
    {
        $open = $this->takeOpenCommand($command);

        if ($open !== null) {
            $this->sendAnswer(new ExposedCommandAnswered($open->commandId, false, $reason));
        }
    }

    public function refuseUnknownCommand(string $commandId, string $reason): void
    {
        $this->sendAnswer(new ExposedCommandAnswered($commandId, false, $reason));
    }

    private function takeOpenCommand(ExposedCommand $command): ?OpenExposedCommand
    {
        $open = $this->openCommands[$command] ?? null;
        unset($this->openCommands[$command]);

        return $open;
    }

    private function sendAnswer(ExposedCommandAnswered $answer): void
    {
        try {
            $this->transport->send($answer);
        } catch (TransportException $e) {
            $this->logger->debug('Could not answer an exposed entity command; the broker channel is closed', ['exception' => $e, 'command' => $answer->commandId]);
        }
    }
}
