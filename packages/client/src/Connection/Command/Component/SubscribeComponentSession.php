<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command\Component;

use Stewart\Client\Component\ComponentProtocol;
use Stewart\Client\Component\ComponentSessionRequest;
use Stewart\Client\Connection\Command\SubscriptionCommand;

final readonly class SubscribeComponentSession implements SubscriptionCommand
{
    public function __construct(public ComponentSessionRequest $request) {}

    public function type(): string
    {
        return 'stewart/session/subscribe';
    }

    public function describe(): string
    {
        return \sprintf('%s "%s"', $this->type(), $this->request->instance);
    }

    public function toMessage(): array
    {
        return [
            'type' => $this->type(),
            'instance' => $this->request->instance->value,
            'protocol' => ComponentProtocol::VERSION,
            'stewart_version' => $this->request->stewartVersion,
            'command_timeout' => $this->request->commandTimeout->toSeconds(),
        ];
    }
}
