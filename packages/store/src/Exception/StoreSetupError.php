<?php

declare(strict_types=1);

namespace Stewart\Store\Exception;

use Stewart\Contracts\Exception\ExceptionReason;

enum StoreSetupError: string implements ExceptionReason
{
    case DsnInvalid = 'dsn_invalid';
    case SchemeUnsupported = 'scheme_unsupported';
    case UnknownPeerApp = 'unknown_peer_app';
    case PrefixInvalid = 'prefix_invalid';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::DsnInvalid => 'Store URL is invalid ({length} characters); expected a URL like "redis://host:6379/0".',
            self::SchemeUnsupported => 'No installed store backend handles "{scheme}://"; installed schemes: {supported}.',
            self::UnknownPeerApp => 'No automation has ID "{appId}", so its store cannot be opened.',
            self::PrefixInvalid => 'Store prefix "{prefix}" is invalid; expected a letter or digit, then up to 63 letters, digits, ".", "_" or "-".',
        };
    }
}
