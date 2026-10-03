<?php

declare(strict_types=1);

namespace Stewart\Codegen\Service;

use Stewart\Codegen\Php\PhpType;

final readonly class FieldModel
{
    public function __construct(
        public string $parameter,
        public ServiceField $field,
    ) {}

    public function type(): PhpType
    {
        return $this->field->required ? $this->field->type : $this->field->type->nullable();
    }

    public function summary(): string
    {
        $parts = array_filter([$this->field->label, $this->field->description, $this->boundsNote()]);

        return implode('. ', array_map(static fn(string $part): string => rtrim($part, '.'), $parts));
    }

    private function boundsNote(): ?string
    {
        $min = $this->field->min;
        $max = $this->field->max;
        $unit = $this->field->unit === null ? '' : ' ' . $this->field->unit;

        return match (true) {
            $min !== null && $max !== null => \sprintf('%s to %s%s', $min, $max, $unit),
            $min !== null => \sprintf('at least %s%s', $min, $unit),
            $max !== null => \sprintf('at most %s%s', $max, $unit),
            $unit !== '' => 'in' . $unit,
            default => null,
        };
    }
}
