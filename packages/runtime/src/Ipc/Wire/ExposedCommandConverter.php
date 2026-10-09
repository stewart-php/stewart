<?php

declare(strict_types=1);

namespace Stewart\Runtime\Ipc\Wire;

use LogicException;
use Stewart\Contracts\Exception\JsonShapeException;
use Stewart\Contracts\Exposure\CalendarStateFormat;
use Stewart\Contracts\Exposure\Command\ButtonPress;
use Stewart\Contracts\Exposure\Command\DateCommand;
use Stewart\Contracts\Exposure\Command\DateTimeCommand;
use Stewart\Contracts\Exposure\Command\ExposedCommand;
use Stewart\Contracts\Exposure\Command\NumberCommand;
use Stewart\Contracts\Exposure\Command\SelectCommand;
use Stewart\Contracts\Exposure\Command\SwitchAction;
use Stewart\Contracts\Exposure\Command\SwitchCommand;
use Stewart\Contracts\Exposure\Command\TextCommand;
use Stewart\Contracts\Exposure\Command\TimeCommand;
use Stewart\Contracts\Exposure\ExposedPlatform;
use Stewart\Contracts\State\EventContext;
use Stewart\Runtime\Json\ValueConverter;

final readonly class ExposedCommandConverter implements ValueConverter
{
    public function handledClass(): string
    {
        return ExposedCommand::class;
    }

    public function keySuffix(): string
    {
        return '';
    }

    /** @return array<string, mixed> */
    public function encodeValue(object $value): array
    {
        \assert($value instanceof ExposedCommand);

        $platformFields = match (true) {
            $value instanceof SwitchCommand => ['platform' => ExposedPlatform::Switch->value, 'action' => $value->action->value],
            $value instanceof ButtonPress => ['platform' => ExposedPlatform::Button->value],
            $value instanceof NumberCommand => ['platform' => ExposedPlatform::Number->value, 'value' => $value->value],
            $value instanceof SelectCommand => ['platform' => ExposedPlatform::Select->value, 'option' => $value->option],
            $value instanceof TextCommand => ['platform' => ExposedPlatform::Text->value, 'value' => $value->value],
            $value instanceof TimeCommand => ['platform' => ExposedPlatform::Time->value, 'value' => CalendarStateFormat::formatTime($value->value)],
            $value instanceof DateCommand => ['platform' => ExposedPlatform::Date->value, 'value' => CalendarStateFormat::formatDate($value->value)],
            $value instanceof DateTimeCommand => ['platform' => ExposedPlatform::DateTime->value, 'value' => CalendarStateFormat::formatDateTime($value->value)],
            default => throw new LogicException(\sprintf('%s has no IPC encoding.', $value::class)),
        };
        $context = $value->getContext();

        return [...$platformFields, 'context' => ['id' => $context->id, 'parent_id' => $context->parentId, 'user_id' => $context->userId]];
    }

    /** @throws JsonShapeException */
    public function decodeValue(mixed $value, string $path): ExposedCommand
    {
        if (!\is_array($value) || !\is_array($value['context'] ?? null)) {
            throw JsonShapeException::wrongType($path, 'an object with a context', get_debug_type($value));
        }

        $context = EventContext::fromArray($value['context']);
        $platform = \is_string($value['platform'] ?? null) ? ExposedPlatform::tryFrom($value['platform']) : null;

        return match ($platform) {
            ExposedPlatform::Switch => new SwitchCommand($this->readSwitchAction($value, $path), $context),
            ExposedPlatform::Button => new ButtonPress($context),
            ExposedPlatform::Number => new NumberCommand($this->readNumber($value, $path), $context),
            ExposedPlatform::Select => new SelectCommand($this->readString($value, 'option', $path), $context),
            ExposedPlatform::Text => new TextCommand($this->readString($value, 'value', $path), $context),
            ExposedPlatform::Time => new TimeCommand(
                CalendarStateFormat::parseTime($this->readString($value, 'value', $path))
                    ?? throw JsonShapeException::unexpectedValue($path . '.value', 'an HH:MM:SS time', $value['value']),
                $context,
            ),
            ExposedPlatform::Date => new DateCommand(
                CalendarStateFormat::parseDate($this->readString($value, 'value', $path))
                    ?? throw JsonShapeException::unexpectedValue($path . '.value', 'a YYYY-MM-DD date', $value['value']),
                $context,
            ),
            ExposedPlatform::DateTime => new DateTimeCommand(
                CalendarStateFormat::parseDateTime($this->readString($value, 'value', $path))
                    ?? throw JsonShapeException::unexpectedValue($path . '.value', 'an ISO 8601 date and time with an offset', $value['value']),
                $context,
            ),
            default => throw JsonShapeException::unexpectedValue($path . '.platform', 'a platform that takes commands', $value['platform'] ?? null),
        };
    }

    /**
     * @param array<array-key, mixed> $fields
     * @throws JsonShapeException
     */
    private function readSwitchAction(array $fields, string $path): SwitchAction
    {
        $raw = $fields['action'] ?? null;

        return (\is_string($raw) ? SwitchAction::tryFrom($raw) : null)
            ?? throw JsonShapeException::unexpectedValue($path . '.action', 'a switch action', $raw);
    }

    /**
     * @param array<array-key, mixed> $fields
     * @throws JsonShapeException
     */
    private function readNumber(array $fields, string $path): int|float
    {
        $raw = $fields['value'] ?? null;

        return \is_int($raw) || \is_float($raw) ? $raw : throw JsonShapeException::wrongType($path . '.value', 'a number', get_debug_type($raw));
    }

    /**
     * @param array<array-key, mixed> $fields
     * @throws JsonShapeException
     */
    private function readString(array $fields, string $key, string $path): string
    {
        $raw = $fields[$key] ?? null;

        return \is_string($raw) ? $raw : throw JsonShapeException::wrongType($path . '.' . $key, 'a string', get_debug_type($raw));
    }
}
