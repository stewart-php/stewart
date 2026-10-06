<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Subject;

use Stewart\Contracts\Exception\ServiceCallException;
use Stewart\Contracts\Service\ServiceResponse;
use Stewart\Runtime\Worker\PendingRequestSubject;

/** @implements PendingRequestSubject<ServiceResponse> */
final readonly class ServiceCallSubject implements PendingRequestSubject
{
    public function __construct(
        public string $domain,
        public string $service,
    ) {}

    public function getResultClass(): string
    {
        return ServiceResponse::class;
    }

    public function createUnreachableFailure(string $detail): ServiceCallException
    {
        return ServiceCallException::unreachable($this->domain, $this->service, $detail);
    }
}
