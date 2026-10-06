<?php

declare(strict_types=1);

namespace Stewart\Runtime\Health;

interface ProbeReporter
{
    public function reportProbe(ProbeKind $kind): ProbeReport;
}
