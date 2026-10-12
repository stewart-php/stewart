<?php

declare(strict_types=1);

namespace Stewart\Support\Wire;

use Stewart\Contracts\Exception\TopicError;
use Stewart\Contracts\Exception\TopicException;

/** @internal */
final readonly class TopicPayload
{
    /**
     * @param array<mixed>|bool|float|int|string|null $value
     * @throws TopicException
     */
    public function __construct(public bool|int|float|string|array|null $value)
    {
        $this->assertTransportable($value, 'payload');
    }

    /** @throws TopicException */
    private function assertTransportable(mixed $value, string $path): void
    {
        if (\is_float($value) && !is_finite($value)) {
            throw TopicException::createForReason(
                TopicError::PayloadInvalid,
                path: $path,
                actualType: \sprintf('float(%s)', is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF')),
            );
        }

        if ($value === null || \is_scalar($value)) {
            return;
        }

        if (!\is_array($value)) {
            throw TopicException::createForReason(TopicError::PayloadInvalid, path: $path, actualType: get_debug_type($value));
        }

        foreach ($value as $key => $item) {
            $this->assertTransportable($item, $path . '[' . $key . ']');
        }
    }
}
