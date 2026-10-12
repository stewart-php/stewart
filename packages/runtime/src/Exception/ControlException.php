<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\Exception\StewartException;

/** @extends StewartException<ControlError> */
final class ControlException extends StewartException
{
    public function findBrokerReason(): ?string
    {
        $brokerReason = $this->context['reason'] ?? null;

        return $this->reason === ControlError::RequestFailed && \is_string($brokerReason) ? $brokerReason : null;
    }
}
