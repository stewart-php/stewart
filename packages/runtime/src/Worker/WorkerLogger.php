<?php

declare(strict_types=1);

namespace Stewart\Runtime\Worker;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Stewart\Contracts\App\AppId;
use Stewart\Runtime\Ipc\Message\LogRecord;
use Stewart\Runtime\Ipc\Transport;
use Stewart\Runtime\Model\ExceptionDetails;
use Stewart\Runtime\Model\LogLevel;
use Stewart\Runtime\Model\ResourceScope;
use Stringable;
use Throwable;

final class WorkerLogger extends AbstractLogger
{
    public function __construct(
        private readonly Transport $transport,
        private readonly StderrFallback $stderr,
        private readonly ResourceScope $resourceScope,
        private readonly LogLevel $workerLogThreshold = LogLevel::Debug,
    ) {}

    public function forApp(AppId $appId): self
    {
        return $this->forScope(ResourceScope::forApp($appId));
    }

    public function forScope(ResourceScope $resourceScope): self
    {
        return new self($this->transport, $this->stderr, $resourceScope, $this->workerLogThreshold);
    }

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     * @throws InvalidArgumentException
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $severity = \is_string($level) ? LogLevel::tryFrom($level) : null;

        if ($severity === null) {
            throw new InvalidArgumentException(\sprintf(
                'Unknown log level "%s"; use one of %s.',
                \is_scalar($level) ? (string) $level : get_debug_type($level),
                implode(', ', array_map(static fn(LogLevel $known): string => $known->value, LogLevel::cases())),
            ));
        }

        if ($severity->isBelow($this->workerLogThreshold)) {
            return;
        }

        try {
            $this->transport->send(new LogRecord(
                scope: $this->resourceScope,
                level: $severity,
                message: (string) $message,
                context: self::serializableContext($context),
                exception: ($context['exception'] ?? null) instanceof Throwable ? ExceptionDetails::fromThrowable($context['exception']) : null,
            ));
        } catch (Throwable) {
            $this->stderr->writeLine($this->resourceScope, \sprintf('%s %s', $severity->value, $message));
        }
    }

    /**
     * @param array<array-key, mixed> $context
     * @return array<string, mixed>
     */
    private static function serializableContext(array $context): array
    {
        $out = [];

        foreach ($context as $key => $value) {
            $out[(string) $key] = self::serializableValue($value);
        }

        return $out;
    }

    private static function serializableValue(mixed $value): mixed
    {
        return match (true) {
            $value === null, \is_scalar($value) => $value,
            $value instanceof Throwable => \sprintf(
                "%s: %s in %s:%d\n%s",
                $value::class,
                $value->getMessage(),
                $value->getFile(),
                $value->getLine(),
                $value->getTraceAsString(),
            ),
            \is_array($value) => array_map(self::serializableValue(...), $value),
            $value instanceof Stringable => (string) $value,
            default => get_debug_type($value),
        };
    }
}
