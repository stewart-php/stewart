<?php

declare(strict_types=1);

namespace Stewart\Contracts\Tests\Fixtures\Exception;

use Stewart\Contracts\Exception\StewartException;
use Throwable;

/**
 * @phpstan-import-type ExceptionContext from StewartException
 *
 * @extends StewartException<SampleError>
 */
final class SampleException extends StewartException
{
    /** @param ExceptionContext $context */
    public static function createForSampleReason(SampleError $reason, array $context = [], ?Throwable $previous = null): self
    {
        return self::createForReason($reason, $context, $previous);
    }
}
