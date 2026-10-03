<?php

declare(strict_types=1);

namespace Stewart\Client\Connection\Command;

use Stewart\Contracts\Service\ServiceTarget;

final readonly class CallService implements HaCommand
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $domain,
        public string $service,
        public array $data = [],
        public ?ServiceTarget $target = null,
        public bool $returnResponse = false,
    ) {}

    public function type(): string
    {
        return 'call_service';
    }

    public function describe(): string
    {
        return \sprintf('call_service %s.%s', $this->domain, $this->service);
    }

    public function toMessage(): array
    {
        $message = ['type' => $this->type(), 'domain' => $this->domain, 'service' => $this->service];

        if ($this->data !== []) {
            $message['service_data'] = $this->data;
        }

        if ($this->target !== null && !$this->target->isEmpty()) {
            $message['target'] = $this->target->toArray();
        }

        if ($this->returnResponse) {
            $message['return_response'] = true;
        }

        return $message;
    }
}
