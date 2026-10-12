<?php

declare(strict_types=1);

namespace Stewart\Runtime\Exception;

use Stewart\Contracts\App\AppId;
use Stewart\Contracts\Exception\StewartException;

/** @extends StewartException<ContainerError> */
final class ContainerException extends StewartException
{
    public static function appOptionUnknown(AppId $appId, string $option, string $class, ?string $suggestion): self
    {
        return self::createForReasonWithAppendedText(
            ContainerError::AppOptionUnknown,
            ['appId' => $appId->value, 'option' => $option, 'class' => $class, 'suggestion' => $suggestion],
            $suggestion === null ? null : \sprintf('Did you mean "%s"?', $suggestion),
        );
    }

    /** @param list<string> $parameters */
    public static function appOptionAmbiguous(AppId $appId, string $option, string $class, array $parameters): self
    {
        return self::createForReason(
            ContainerError::AppOptionAmbiguous,
            appId: $appId->value,
            option: $option,
            class: $class,
            parameters: implode(', ', array_map(static fn(string $name): string => '$' . $name, $parameters)),
        );
    }
}
