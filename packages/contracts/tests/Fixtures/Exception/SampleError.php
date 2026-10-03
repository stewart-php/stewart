<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Fixtures\Exception;

use Stewart\Contracts\Exception\ExceptionReason;

enum SampleError: string implements ExceptionReason
{
    case Plain = 'plain';
    case Placeholders = 'placeholders';
    case Caused = 'caused';

    public function messageTemplate(): string
    {
        return match ($this) {
            self::Plain => 'Nothing to fill.',
            self::Placeholders => 'Key "{key}", count {count}, flag {flag}, list {items}, missing {absent}.',
            self::Caused => 'Failed: {cause}',
        };
    }
}
