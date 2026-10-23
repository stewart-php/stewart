<?php

declare(strict_types=1);

namespace Stewart\Runtime\Http;

use Amp\Http\Server\Request;
use Psr\Log\LoggerInterface;
use Stewart\Runtime\Logging\EveryNthOccurrence;

// A scanner or a misconfigured scraper repeats the same refusal, so the warnings are sampled.
final class UnauthorizedRequestWarnings
{
    private int $refused = 0;

    private readonly EveryNthOccurrence $reports;

    public function __construct(
        private readonly HttpListenerRole $role,
        private readonly LoggerInterface $logger,
    ) {
        $this->reports = EveryNthOccurrence::forRepeatedWarnings();
    }

    public function recordRefusedRequest(Request $request): void
    {
        ++$this->refused;

        if ($this->reports->includesOccurrence($this->refused)) {
            $this->logger->warning('HTTP request refused: no valid bearer token', [
                'listener' => $this->role->value,
                'client' => $request->getClient()->getRemoteAddress()->toString(),
                'path' => $request->getUri()->getPath(),
                'refused' => $this->refused,
            ]);
        }
    }
}
