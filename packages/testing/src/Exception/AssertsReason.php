<?php

declare(strict_types=1);

namespace Stewart\Testing\Exception;

use Closure;
use PHPUnit\Framework\TestCase;
use Stewart\Contracts\Exception\ExceptionReason;
use Stewart\Contracts\Exception\StewartException;

/** @phpstan-require-extends TestCase */
trait AssertsReason
{
    /**
     * @param Closure(): mixed $action
     * @return StewartException<ExceptionReason>
     */
    protected function assertThrowsReason(ExceptionReason $reason, Closure $action): StewartException
    {
        $exceptionClass = preg_replace('/Error$/', 'Exception', $reason::class);
        \assert(\is_string($exceptionClass) && is_subclass_of($exceptionClass, StewartException::class));

        try {
            $action();
        } catch (StewartException $e) {
            self::assertInstanceOf($exceptionClass, $e);
            self::assertSame($reason, $e->reason, $e->getMessage());

            return $e;
        }

        self::fail(\sprintf('Expected %s to be thrown with reason %s.', $exceptionClass, $reason->name));
    }
}
