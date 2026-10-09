<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker\Subject;

use Stewart\Contracts\Exception\ExposureException;
use Stewart\Contracts\Exposure\ExposedEntityKey;
use Stewart\Runtime\Ipc\Message\ExposureAcknowledged;
use Stewart\Runtime\Worker\PendingRequestSubject;

/** @implements PendingRequestSubject<ExposureAcknowledged> */
final readonly class ExposureChangeSubject implements PendingRequestSubject
{
    public function __construct(public ExposedEntityKey $key) {}

    public function getResultClass(): string
    {
        return ExposureAcknowledged::class;
    }

    public function createUnreachableFailure(string $detail): ExposureException
    {
        return ExposureException::unreachable($this->key, $detail);
    }
}
