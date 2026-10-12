<?php

declare(strict_types=1);

namespace Stewart\Contracts\Exception;

use LogicException;
use RuntimeException;
use Throwable;

/**
 * @phpstan-type ExceptionContext array<string, scalar|null|list<scalar|null>>
 *
 * @template-covariant TReason of ExceptionReason
 */
abstract class StewartException extends RuntimeException
{
    private const string CAUSE_PLACEHOLDER = 'cause';

    /**
     * @param TReason $reason
     * @param ExceptionContext|null $context
     */
    final protected function __construct(
        string $message,
        public readonly ExceptionReason $reason,
        public readonly ?array $context = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @template TRaisedReason of ExceptionReason
     *
     * @param TRaisedReason $exceptionReason
     * @param scalar|null|list<scalar|null> ...$context
     * @return static<TRaisedReason>
     */
    final public static function createForReason(
        ExceptionReason $exceptionReason,
        ?Throwable $previous = null,
        string|int|float|bool|array|null ...$context,
    ): static {
        return self::createForReasonWithAppendedText($exceptionReason, self::requireNamedContext($context), null, $previous);
    }

    /**
     * @template TRaisedReason of ExceptionReason
     *
     * @param TRaisedReason $reason
     * @param ExceptionContext $context
     * @return static<TRaisedReason>
     */
    final protected static function createForReasonWithAppendedText(
        ExceptionReason $reason,
        array $context,
        ?string $appendedText,
        ?Throwable $previous = null,
    ): static {
        $message = self::renderMessage($reason, $context, $previous);

        return new static(
            $appendedText === null ? $message : $message . ' ' . $appendedText,
            $reason,
            $context === [] ? null : $context,
            $previous,
        );
    }

    /**
     * @template TRestoredReason of ExceptionReason
     *
     * @param TRestoredReason $reason
     * @param ExceptionContext|null $context
     * @return static<TRestoredReason>
     */
    final public static function restoreFromWire(ExceptionReason $reason, string $message, ?array $context): static
    {
        return new static($message, $reason, $context);
    }

    protected function findContextString(string $key): ?string
    {
        $value = $this->context[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<array-key, scalar|null|list<scalar|null>> $context
     * @return ExceptionContext
     */
    private static function requireNamedContext(array $context): array
    {
        $named = [];

        foreach ($context as $key => $value) {
            $named[\is_string($key) ? $key : throw new LogicException('Pass the exception context as named arguments.')] = $value;
        }

        return $named;
    }

    /** @param ExceptionContext $context */
    private static function renderMessage(ExceptionReason $reason, array $context, ?Throwable $previous): string
    {
        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static fn(array $placeholder): string => self::renderPlaceholder($placeholder[1], $context, $previous),
            $reason->messageTemplate(),
        );
    }

    /** @param ExceptionContext $context */
    private static function renderPlaceholder(string $name, array $context, ?Throwable $previous): string
    {
        if ($name === self::CAUSE_PLACEHOLDER) {
            return $previous?->getMessage() ?? throw new LogicException('{cause} needs a previous exception.');
        }

        if (!\array_key_exists($name, $context)) {
            throw new LogicException(\sprintf('The message template names {%s}, which the context lacks.', $name));
        }

        $value = $context[$name];

        return \is_array($value)
            ? implode(', ', array_map(self::renderScalar(...), $value))
            : self::renderScalar($value);
    }

    private static function renderScalar(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => 'none',
            \is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
