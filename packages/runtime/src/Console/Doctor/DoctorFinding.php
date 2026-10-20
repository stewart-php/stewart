<?php

declare(strict_types=1);

namespace Stewart\Runtime\Console\Doctor;

final readonly class DoctorFinding
{
    public function __construct(
        public string $label,
        public DoctorOutcome $outcome,
        public string $detail = '',
    ) {}
}
