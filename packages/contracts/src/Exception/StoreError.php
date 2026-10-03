<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

enum StoreError: string implements ExceptionReason
{
    case KeyInvalid = 'key_invalid';
    case ValueNotEncodable = 'value_not_encodable';
    case ValueNotAMap = 'value_not_a_map';
    case NotConfigured = 'not_configured';
    case Unreachable = 'unreachable';
    case Refused = 'refused';
    case TimedOut = 'timed_out';
    case RecentlyFailed = 'recently_failed';
    case TypeMismatch = 'type_mismatch';
    case NotIncrementable = 'not_incrementable';
    case ValueNotIncrementable = 'value_not_incrementable';
    case ValueMalformed = 'value_malformed';
    case ValueRejected = 'value_rejected';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::KeyInvalid => 'Store key "{key}" is invalid; expected a letter or digit, then up to 127 letters, digits, ".", "_", ":" or "-".',
            self::ValueNotEncodable => 'The value for key "{key}" is not JSON-encodable: {cause}',
            self::ValueNotAMap => '{class}::toStorage() returned a list for key "{key}"; expected a map of named fields.',
            self::NotConfigured => 'Persistence is not configured; set persistence.url in stewart.yaml or STEWART_PERSISTENCE__URL in .env.',
            self::Unreachable => 'The store at {target} is unreachable: {detail}',
            self::Refused => 'The store at {target} refused the operation: {detail}',
            self::TimedOut => 'The store at {target} did not answer within {timeout}.',
            self::RecentlyFailed => 'The store at {target} failed moments ago and is not being retried yet: {detail}',
            self::TypeMismatch => 'Key "{key}" holds {actual}, not {expected}.',
            self::NotIncrementable => 'Key "{key}" cannot be incremented: {detail}',
            self::ValueNotIncrementable => 'The stored value cannot be incremented: {detail}',
            self::ValueMalformed => 'Key "{key}" does not hold valid JSON: {cause}',
            self::ValueRejected => '{class}::fromStorage() rejected the value of key "{key}" (handle the old shape or delete the key): {cause}',
        };
    }
}
