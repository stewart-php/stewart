<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command\Component;

use Stewart\Client\Component\ComponentCommandAnswer;
use Stewart\Client\Connection\Command\HaCommand;

final readonly class AnswerComponentCommand implements HaCommand
{
    public function __construct(public ComponentCommandAnswer $answer) {}

    public function type(): string
    {
        return 'stewart/command/result';
    }

    public function describe(): string
    {
        return \sprintf('%s %s', $this->type(), $this->answer->commandId);
    }

    public function toMessage(): array
    {
        $message = ['type' => $this->type(), 'command_id' => $this->answer->commandId, 'ok' => $this->answer->accepted];

        if ($this->answer->message !== null) {
            $message['message'] = $this->answer->message;
        }

        return $message;
    }
}
