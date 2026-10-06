<?php

declare(strict_types=1);

namespace Stewart\Contracts\Event;

use Stewart\Contracts\Exception\EventFireException;

final readonly class EventPayload
{
    private const string VALID_EVENT_TYPE_PATTERN = '/\A.{1,64}\z/su';

    /**
     * @param array<string, mixed> $data
     * @throws EventFireException
     */
    public function __construct(
        public string $eventType,
        public array $data = [],
    ) {
        if (preg_match(self::VALID_EVENT_TYPE_PATTERN, $eventType) !== 1) {
            throw EventFireException::typeInvalid($eventType);
        }

        if ($data !== [] && array_is_list($data)) {
            throw EventFireException::dataNotKeyed($eventType);
        }

        foreach ($data as $key => $value) {
            $this->assertTransportable($value, (string) $key);
        }
    }

    /** @throws EventFireException */
    private function assertTransportable(mixed $value, string $path): void
    {
        if (\is_float($value) && !is_finite($value)) {
            throw EventFireException::dataInvalid($this->eventType, $path, \sprintf('float(%s)', is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF')));
        }

        if ($value === null || \is_scalar($value)) {
            return;
        }

        if (!\is_array($value)) {
            throw EventFireException::dataInvalid($this->eventType, $path, get_debug_type($value));
        }

        foreach ($value as $key => $item) {
            $this->assertTransportable($item, $path . '[' . $key . ']');
        }
    }
}
