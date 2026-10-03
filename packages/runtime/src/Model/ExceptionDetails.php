<?php

declare(strict_types=1);

namespace Stewart\Runtime\Model;

use Stewart\Contracts\Exception\StewartException;
use Throwable;

/** @phpstan-import-type ExceptionContext from StewartException */
final readonly class ExceptionDetails
{
    public const string CONTEXT_KEY = 'stewartExceptionDetails';

    /** @param ExceptionContext|null $context */
    public function __construct(
        public string $class,
        public string $reason,
        public ?array $context,
    ) {}

    public static function fromThrowable(Throwable $error): ?self
    {
        return $error instanceof StewartException
            ? new self($error::class, (string) $error->reason->value, $error->context)
            : null;
    }

    /** @return array{class: string, reason: string, context: ExceptionContext|null} */
    public function toArray(): array
    {
        return ['class' => $this->class, 'reason' => $this->reason, 'context' => $this->context];
    }
}
