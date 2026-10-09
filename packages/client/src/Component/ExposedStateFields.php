<?php

declare(strict_types=1);

namespace Stewart\Client\Component;

use Stewart\Contracts\Exposure\ExposedStateChange;

final class ExposedStateFields
{
    /** @return array<string, mixed> */
    public static function formatStateChange(ExposedStateChange $change): array
    {
        $fields = [];

        if ($change->state !== null) {
            $fields['state'] = $change->state->value;
        }

        if ($change->attributes !== null) {
            $fields['attributes'] = $change->attributes;
        }

        if ($change->available !== null) {
            $fields['available'] = $change->available;
        }

        return $fields;
    }
}
