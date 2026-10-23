<?php

declare(strict_types=1);

namespace Stewart\Testing\Exception;

use Stewart\Contracts\Exception\StewartException;

/** @extends StewartException<HarnessError> */
final class HarnessException extends StewartException
{
    public static function optionUnknown(string $option, string $class, ?string $suggestion): self
    {
        return self::createForReasonWithAppendedText(
            HarnessError::OptionUnknown,
            ['option' => $option, 'class' => $class, 'suggestion' => $suggestion],
            $suggestion === null ? null : \sprintf('Did you mean "%s"?', $suggestion),
        );
    }
}
