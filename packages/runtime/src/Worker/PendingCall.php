<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Amp\DeferredFuture;
use Amp\Future;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Runtime\Model\CorrelationId;
use Throwable;

final readonly class PendingCall
{
    /** @var DeferredFuture<ServiceResponse> */
    private DeferredFuture $deferred;

    public function __construct(
        public CorrelationId $correlationId,
        public string $domain,
        public string $service,
    ) {
        /** @var DeferredFuture<ServiceResponse> $deferred */
        $deferred = new DeferredFuture();
        $this->deferred = $deferred;
    }

    /** @return Future<ServiceResponse> */
    public function getFuture(): Future
    {
        return $this->deferred->getFuture();
    }

    public function complete(ServiceResponse $response): void
    {
        $this->deferred->complete($response);
    }

    public function fail(Throwable $error): void
    {
        $this->deferred->error($error);
    }
}
